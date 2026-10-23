<?php

namespace App\Support;

/**
 * Relevés PDF protégés. Beaucoup de banques chiffrent leurs relevés (sans mot de passe pour
 * les ouvrir, seulement pour en empêcher la modification) : la bibliothèque de lecture les
 * refuse. Ici, le PDF est déchiffré (méthode « Standard » : RC4 40 / 128 bits, AES 128 et
 * 256 bits) et réécrit en PDF ordinaire, en mémoire seulement.
 */
final class PdfDecryptor
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private const WHITESPACE = " \t\r\n\f\0";

    private const DELIMITERS = " \t\r\n\f\0()<>[]{}/%";

    /** Clé du fichier. */
    private string $key = '';

    /** rc4, aesv2 (AES 128), aesv3 (AES 256) ou none, pour les textes et pour les flux. */
    private string $stringMethod = 'none';

    private string $streamMethod = 'none';

    private bool $encryptMetadata = true;

    private function __construct(private readonly string $pdf) {}

    public static function isEncrypted(string $pdf): bool
    {
        return (bool) preg_match('/\/Encrypt\s*(?:\d+\s+\d+\s+R|<<)/', $pdf);
    }

    /** Type de protection, en clair (« AES 256 bits »…). */
    public static function describe(string $pdf): string
    {
        $dict = '';
        if (preg_match_all('/\/Encrypt\s*(\d+)\s+\d+\s+R/', $pdf, $m) && preg_match('/(?<![0-9])'.end($m[1]).'\s+\d+\s+obj(.{0,2000}?)endobj/s', $pdf, $o)) {
            $dict = $o[1];
        } elseif (preg_match('/\/Encrypt\s*(<<.{0,2000}?>>)/s', $pdf, $o)) {
            $dict = $o[1];
        }
        if (! str_contains($dict, '/Standard')) {
            return 'certificat ou autre (non pris en charge)';
        }

        return match (true) {
            str_contains($dict, 'AESV3') || (bool) preg_match('/\/V\s+5\b/', $dict) => 'AES 256 bits',
            str_contains($dict, 'AESV2') => 'AES 128 bits',
            (bool) preg_match('/\/R\s+2\b/', $dict) => 'RC4 40 bits',
            default => 'RC4 128 bits',
        };
    }

    /**
     * Le PDF déchiffré. null : un mot de passe est nécessaire pour l'ouvrir, ou la protection
     * n'est pas de type « Standard » (certificat…).
     */
    public static function decrypt(string $pdf, string $password = ''): ?string
    {
        try {
            return (new self($pdf))->run($password);
        } catch (\Throwable) {
            return null;
        }
    }

    private function run(string $password): ?string
    {
        // Dictionnaire de chiffrement et identifiant du fichier (dans le dernier « trailer »).
        $encryptRef = null;
        if (preg_match_all('/\/Encrypt\s*(\d+)\s+(\d+)\s+R/', $this->pdf, $m)) {
            $encryptRef = (int) end($m[1]);
            $encrypt = $this->objectValue($encryptRef);
        } elseif (preg_match('/\/Encrypt\s*(<<)/', $this->pdf, $m, PREG_OFFSET_CAPTURE)) {
            $p = $m[1][1];
            $encrypt = $this->value($this->pdf, $p);
        }
        if (! isset($encrypt) || ($encrypt['t'] ?? null) !== 'dict' || self::name($encrypt, 'Filter') !== 'Standard') {
            return null;
        }
        $id = '';
        if (preg_match_all('/\/ID\s*\[/', $this->pdf, $m, PREG_OFFSET_CAPTURE)) {
            $p = end($m[0])[1] + strlen(end($m[0])[0]) - 1;
            $ids = $this->value($this->pdf, $p);
            $id = $ids['v'][0]['v'] ?? '';
        }
        if (! $this->setUp($encrypt, $password, (string) $id)) {
            return null;
        }

        // Les objets, l'un après l'autre (une définition plus loin remplace la précédente).
        $objects = [];
        $offset = 0;
        while (preg_match('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->pdf, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $num = (int) $m[1][0];
            $gen = (int) $m[2][0];
            $p = $m[0][1] + strlen($m[0][0]);
            $start = $p;
            $value = $this->value($this->pdf, $p);
            $body = substr($this->pdf, $start, $p - $start);
            $stream = null;
            $q = $p + strspn($this->pdf, self::WHITESPACE, $p);
            if (substr($this->pdf, $q, 6) === 'stream') {
                [$stream, $p] = $this->streamData($value, $q + 6);
            }
            $end = strpos($this->pdf, 'endobj', $p);
            $offset = $end === false ? $p : $end + 6;
            if ($num === $encryptRef || ($value['t'] === 'dict' && self::name($value, 'Type') === 'XRef')) {
                continue;
            }
            $objects[$num] = [$gen, $value, $body, $stream, $m[0][1], false];
        }
        if ($objects === []) {
            return null;
        }

        // Objets rangés dans des flux d'objets (déchiffrés en bloc) : remis à part, en clair.
        foreach ($objects as $num => [$gen, $value, $body, $stream, $at]) {
            if ($stream === null || self::name($value, 'Type') !== 'ObjStm') {
                continue;
            }
            $data = $this->inflate($this->decryptBytes($stream, $this->streamMethod, $num, $gen), $value);
            if ($data === null) {
                continue;
            }
            $first = (int) self::num($value, 'First');
            preg_match_all('/(\d+)\s+(\d+)/', substr($data, 0, $first), $pairs, PREG_SET_ORDER);
            foreach ($pairs as $i => $pair) {
                $inner = (int) $pair[1];
                // Déjà défini plus loin dans le fichier (mise à jour) : cette version-là compte.
                if (isset($objects[$inner]) && $objects[$inner][4] > $at) {
                    continue;
                }
                $start = $first + (int) $pair[2];
                $end = isset($pairs[$i + 1]) ? $first + (int) $pairs[$i + 1][2] : strlen($data);
                $text = substr($data, $start, $end - $start);
                $q = 0;
                $objects[$inner] = [0, $this->value($text, $q), $text, null, $at, true];
            }
            unset($objects[$num]);
        }

        // PDF ordinaire : textes et flux déchiffrés, table des objets refaite.
        preg_match('/^%PDF-\d\.\d/', ltrim($this->pdf), $header);
        $out = ($header[0] ?? '%PDF-1.7')."\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $num => [$gen, $value, $body, $stream, , $plain]) {
            if (! $plain) {
                $body = $this->rewriteStrings($body, $num, $gen);
            }
            if ($stream !== null) {
                $skip = $value['t'] === 'dict' && (
                    (! $this->encryptMetadata && self::name($value, 'Type') === 'Metadata')
                    || self::name($value, 'Filter') === 'Crypt'
                );
                if (! $skip) {
                    $stream = $this->decryptBytes($stream, $this->streamMethod, $num, $gen);
                }
                $length = '/Length '.strlen($stream);
                $replaced = preg_replace('/\/Length(?=[\s\/\[<(])\s*(?:\d+\s+\d+\s+R|\d+)/', $length, $body, 1, $count);
                $body = $count ? $replaced : preg_replace('/^\s*<</', '<<'.$length.' ', $body, 1);
            }
            $offsets[$num] = [strlen($out), $gen];
            $out .= $num.' '.$gen." obj\n".trim($body).($stream !== null ? "\nstream\n".$stream."\nendstream" : '')."\nendobj\n";
        }
        $size = max(array_keys($offsets)) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 ".$size."\n";
        for ($i = 0; $i < $size; $i++) {
            $out .= isset($offsets[$i])
                ? sprintf("%010d %05d n \n", $offsets[$i][0], $offsets[$i][1])
                : "0000000000 65535 f \n";
        }
        $trailer = '/Size '.$size;
        foreach (['Root', 'Info'] as $key) {
            if (preg_match_all('/\/'.$key.'\s+(\d+)\s+(\d+)\s+R/', $this->pdf, $m) && isset($offsets[(int) end($m[1])])) {
                $trailer .= ' /'.$key.' '.end($m[1]).' '.end($m[2]).' R';
            }
        }

        return $out.'trailer << '.$trailer." >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** Clé du fichier à partir du mot de passe (vide le plus souvent), vérifiée. */
    private function setUp(array $encrypt, string $password, string $id): bool
    {
        $v = (int) self::num($encrypt, 'V');
        $r = (int) self::num($encrypt, 'R');
        $o = (string) ($encrypt['v']['O']['v'] ?? '');
        $u = (string) ($encrypt['v']['U']['v'] ?? '');
        $this->encryptMetadata = ($encrypt['v']['EncryptMetadata']['v'] ?? 'true') !== 'false';

        if ($v === 5 || $r >= 5) {
            [$this->stringMethod, $this->streamMethod] = [$this->filterMethod($encrypt, 'StrF', 'aesv3'), $this->filterMethod($encrypt, 'StmF', 'aesv3')];
            $key = $this->aes256Key($password, $r, $o, $u, (string) ($encrypt['v']['OE']['v'] ?? ''), (string) ($encrypt['v']['UE']['v'] ?? ''));
            if ($key === null) {
                return false;
            }
            $this->key = $key;

            return true;
        }

        if ($v === 4) {
            $this->stringMethod = $this->filterMethod($encrypt, 'StrF', 'rc4');
            $this->streamMethod = $this->filterMethod($encrypt, 'StmF', 'rc4');
            $length = 16;
        } else {
            $this->stringMethod = $this->streamMethod = 'rc4';
            $length = $r === 2 ? 5 : intdiv((int) (self::num($encrypt, 'Length') ?: 40), 8);
        }
        $p = (int) self::num($encrypt, 'P');

        // Mot de passe d'ouverture, sinon mot de passe propriétaire.
        foreach ([$password, $this->userFromOwner($password, $o, $r, $length)] as $candidate) {
            $key = $this->legacyKey($candidate, $o, $p, $id, $r, $length);
            if ($this->checkUser($key, $u, $id, $r)) {
                $this->key = $key;

                return true;
            }
        }

        return false;
    }

    /** Méthode d'un filtre de chiffrement (PDF 1.5+) : AESV2, V2 (RC4) ou Identity. */
    private function filterMethod(array $encrypt, string $which, string $default): string
    {
        $name = self::name($encrypt, $which) ?? 'Identity';
        if ($name === 'Identity') {
            return 'none';
        }
        $filters = $encrypt['v']['CF']['v'] ?? [];
        $cfm = isset($filters[$name]) ? self::name($filters[$name], 'CFM') : null;

        return match ($cfm) {
            'AESV2' => 'aesv2',
            'AESV3' => 'aesv3',
            'V2' => 'rc4',
            'None' => 'none',
            default => $default,
        };
    }

    private function legacyKey(string $password, string $o, int $p, string $id, int $r, int $length): string
    {
        $data = substr($password.self::PAD, 0, 32).substr($o, 0, 32).pack('V', $p & 0xFFFFFFFF).$id;
        if ($r >= 4 && ! $this->encryptMetadata) {
            $data .= "\xFF\xFF\xFF\xFF";
        }
        $hash = md5($data, true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $length), true);
            }
        }

        return substr($hash, 0, $length);
    }

    private function checkUser(string $key, string $u, string $id, int $r): bool
    {
        if ($r === 2) {
            return self::rc4($key, self::PAD) === substr($u, 0, 32);
        }
        $x = self::rc4($key, md5(self::PAD.$id, true));
        for ($i = 1; $i <= 19; $i++) {
            $x = self::rc4(self::xorKey($key, $i), $x);
        }

        return $x === substr($u, 0, 16);
    }

    /** Mot de passe d'ouverture retrouvé à partir du mot de passe propriétaire. */
    private function userFromOwner(string $owner, string $o, int $r, int $length): string
    {
        $hash = md5(substr($owner.self::PAD, 0, 32), true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5($hash, true);
            }
        }
        $key = substr($hash, 0, $length);
        if ($r === 2) {
            return self::rc4($key, substr($o, 0, 32));
        }
        $x = substr($o, 0, 32);
        for ($i = 19; $i >= 0; $i--) {
            $x = self::rc4(self::xorKey($key, $i), $x);
        }

        return $x;
    }

    /** AES 256 bits (PDF 2.0 et Acrobat X) : clé du fichier, avec le mot de passe d'ouverture ou propriétaire. */
    private function aes256Key(string $password, int $r, string $o, string $u, string $oe, string $ue): ?string
    {
        $password = substr($password, 0, 127);
        $zero = str_repeat("\0", 16);
        if ($this->hash2b($password, substr($u, 32, 8), '', $r) === substr($u, 0, 32)) {
            $key = openssl_decrypt($ue, 'aes-256-cbc', $this->hash2b($password, substr($u, 40, 8), '', $r), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $zero);
        } elseif ($this->hash2b($password, substr($o, 32, 8), substr($u, 0, 48), $r) === substr($o, 0, 32)) {
            $key = openssl_decrypt($oe, 'aes-256-cbc', $this->hash2b($password, substr($o, 40, 8), substr($u, 0, 48), $r), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $zero);
        }

        return isset($key) && is_string($key) && strlen($key) === 32 ? $key : null;
    }

    private function hash2b(string $password, string $salt, string $udata, int $r): string
    {
        $k = hash('sha256', $password.$salt.$udata, true);
        if ($r < 6) {
            return $k;
        }
        for ($i = 0; ;) {
            $k1 = str_repeat($password.$k.$udata, 64);
            $e = (string) openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $sum = 0;
            for ($j = 0; $j < 16; $j++) {
                $sum += ord($e[$j]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            $i++;
            if ($i >= 64 && ord($e[strlen($e) - 1]) <= $i - 32) {
                break;
            }
        }

        return substr($k, 0, 32);
    }

    private function decryptBytes(string $data, string $method, int $num, int $gen): string
    {
        return match ($method) {
            'rc4' => self::rc4($this->objectKey($num, $gen, false), $data),
            'aesv2' => self::aes($data, $this->objectKey($num, $gen, true), 'aes-128-cbc'),
            'aesv3' => self::aes($data, $this->key, 'aes-256-cbc'),
            default => $data,
        };
    }

    private function objectKey(int $num, int $gen, bool $aes): string
    {
        $data = $this->key.substr(pack('V', $num), 0, 3).substr(pack('V', $gen), 0, 2).($aes ? 'sAlT' : '');

        return substr(md5($data, true), 0, min(strlen($this->key) + 5, 16));
    }

    private static function aes(string $data, string $key, string $cipher): string
    {
        if (strlen($data) < 32) {
            return '';
        }
        $iv = substr($data, 0, 16);
        $body = substr($data, 16, intdiv(strlen($data) - 16, 16) * 16);
        $out = openssl_decrypt($body, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($out === false) {
            // Remplissage abîmé : déchiffré tel quel.
            $out = openssl_decrypt($body, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        }

        return $out === false ? '' : $out;
    }

    private static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $length = strlen($key);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $length])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $out = '';
        $n = strlen($data);
        for ($k = 0, $i = 0, $j = 0; $k < $n; $k++) {
            $i = ($i + 1) & 255;
            $j = ($j + $s[$i]) & 255;
            $t = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $t;
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }

        return $out;
    }

    private static function xorKey(string $key, int $value): string
    {
        $out = '';
        for ($i = 0, $n = strlen($key); $i < $n; $i++) {
            $out .= chr(ord($key[$i]) ^ $value);
        }

        return $out;
    }

    /** Flux d'objets décompressé (FlateDecode, le seul utilisé pour eux en pratique). */
    private function inflate(string $data, array $dict): ?string
    {
        $filter = $dict['v']['Filter'] ?? null;
        $filters = match ($filter['t'] ?? null) {
            'name' => [$filter['v']],
            'arr' => array_map(fn ($f) => $f['v'] ?? '', $filter['v']),
            default => [],
        };
        foreach ($filters as $name) {
            if ($name !== 'FlateDecode') {
                return null;
            }
            $out = @gzuncompress($data);
            $data = $out === false ? @gzinflate(substr($data, 2)) : $out;
            if ($data === false) {
                return null;
            }
        }

        return $data;
    }

    // ---- Lecture de la syntaxe PDF ----

    /** Données d'un flux, et la position juste après. */
    private function streamData(array $dict, int $p): array
    {
        if (substr($this->pdf, $p, 2) === "\r\n") {
            $p += 2;
        } elseif (in_array($this->pdf[$p] ?? '', ["\n", "\r"], true)) {
            $p++;
        }
        $length = $dict['v']['Length'] ?? null;
        $length = match ($length['t'] ?? null) {
            'num' => (int) $length['v'],
            'ref' => (int) ($this->objectValue($length['v'][0])['v'] ?? -1),
            default => -1,
        };
        if ($length >= 0 && preg_match('/\G\s*endstream/', $this->pdf, $m, 0, $p + $length)) {
            return [substr($this->pdf, $p, $length), $p + $length];
        }
        // Longueur absente ou fausse : jusqu'à « endstream ».
        $end = strpos($this->pdf, 'endstream', $p);
        if ($end === false) {
            return [substr($this->pdf, $p), strlen($this->pdf)];
        }
        $data = substr($this->pdf, $p, $end - $p);
        if (str_ends_with($data, "\r\n")) {
            $data = substr($data, 0, -2);
        } elseif (str_ends_with($data, "\n") || str_ends_with($data, "\r")) {
            $data = substr($data, 0, -1);
        }

        return [$data, $end];
    }

    /** Valeur d'un objet « N 0 obj … endobj » (la dernière définition). */
    private function objectValue(int $num): ?array
    {
        if (! preg_match_all('/(?<![0-9])'.$num.'\s+\d+\s+obj\b/', $this->pdf, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($m[0]);
        $p = $last[1] + strlen($last[0]);

        return $this->value($this->pdf, $p);
    }

    /** Une valeur PDF à partir de la position $p (avancée jusqu'après la valeur). */
    private function value(string $s, int &$p): array
    {
        $n = strlen($s);
        $this->skip($s, $p);
        $c = $s[$p] ?? '';
        if ($c === '<' && ($s[$p + 1] ?? '') === '<') {
            $p += 2;
            $items = [];
            while ($p < $n) {
                $this->skip($s, $p);
                if (substr($s, $p, 2) === '>>') {
                    $p += 2;
                    break;
                }
                $before = $p;
                $key = $this->value($s, $p);
                if ($p === $before) {
                    break;
                }
                if ($key['t'] !== 'name') {
                    continue;
                }
                $items[$key['v']] = $this->value($s, $p);
            }

            return ['t' => 'dict', 'v' => $items];
        }
        if ($c === '[') {
            $p++;
            $items = [];
            while ($p < $n) {
                $this->skip($s, $p);
                if (($s[$p] ?? '') === ']') {
                    $p++;
                    break;
                }
                $before = $p;
                $items[] = $this->value($s, $p);
                if ($p === $before) {
                    break;
                }
            }

            return ['t' => 'arr', 'v' => $items];
        }
        if ($c === '(') {
            return ['t' => 'str', 'v' => $this->literal($s, $p)];
        }
        if ($c === '<') {
            return ['t' => 'str', 'v' => $this->hex($s, $p)];
        }
        if ($c === '/') {
            $p++;
            $len = strcspn($s, self::DELIMITERS, $p);
            $name = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), substr($s, $p, $len));
            $p += $len;

            return ['t' => 'name', 'v' => $name];
        }
        if (preg_match('/\G([+-]?(?:\d+\.?\d*|\.\d+))/', $s, $m, 0, $p)) {
            $p += strlen($m[1]);
            // Référence « 12 0 R ».
            if (ctype_digit($m[1]) && preg_match('/\G\s+(\d+)\s+R(?=[\s\/\[\]<>()%]|$)/', $s, $r, 0, $p)) {
                $p += strlen($r[0]);

                return ['t' => 'ref', 'v' => [(int) $m[1], (int) $r[1]]];
            }

            return ['t' => 'num', 'v' => str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1]];
        }
        $len = max(1, strcspn($s, self::DELIMITERS, $p));
        $word = substr($s, $p, $len);
        // Mot-clé (true, false, null) ; « stream » et « endobj » ne sont pas consommés.
        if (in_array($word, ['stream', 'endobj', 'endstream'], true)) {
            return ['t' => 'kw', 'v' => $word];
        }
        $p += $len;

        return ['t' => 'kw', 'v' => $word];
    }

    private function skip(string $s, int &$p): void
    {
        $n = strlen($s);
        while ($p < $n) {
            $p += strspn($s, self::WHITESPACE, $p);
            if (($s[$p] ?? '') !== '%') {
                return;
            }
            $p += strcspn($s, "\r\n", $p);
        }
    }

    private function literal(string $s, int &$p): string
    {
        $p++;
        $n = strlen($s);
        $depth = 1;
        $out = '';
        while ($p < $n) {
            $c = $s[$p];
            if ($c === '\\') {
                $e = $s[$p + 1] ?? '';
                $p += 2;
                if (isset(['n' => 1, 'r' => 1, 't' => 1, 'b' => 1, 'f' => 1][$e])) {
                    $out .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$e];
                } elseif ($e === "\r") {
                    if (($s[$p] ?? '') === "\n") {
                        $p++;
                    }
                } elseif ($e === "\n") {
                    // Suite à la ligne : rien.
                } elseif ($e >= '0' && $e <= '7') {
                    $octal = $e;
                    while (strlen($octal) < 3 && ($s[$p] ?? '') >= '0' && ($s[$p] ?? '') <= '7') {
                        $octal .= $s[$p++];
                    }
                    $out .= chr(octdec($octal) & 255);
                } else {
                    $out .= $e;
                }

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                $p++;

                return $out;
            }
            $out .= $c;
            $p++;
        }

        return $out;
    }

    private function hex(string $s, int &$p): string
    {
        $end = strpos($s, '>', $p);
        $end = $end === false ? strlen($s) : $end;
        $digits = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $p + 1, $end - $p - 1)) ?? '';
        $p = $end + 1;
        if (strlen($digits) % 2) {
            $digits .= '0';
        }

        return (string) hex2bin($digits);
    }

    /** Les textes d'un objet, déchiffrés et réécrits en hexadécimal. */
    private function rewriteStrings(string $body, int $num, int $gen): string
    {
        if ($this->stringMethod === 'none') {
            return $body;
        }
        $out = '';
        $n = strlen($body);
        $p = 0;
        while ($p < $n) {
            $span = strcspn($body, '(<%', $p);
            $out .= substr($body, $p, $span);
            $p += $span;
            if ($p >= $n) {
                break;
            }
            $c = $body[$p];
            if ($c === '%') {
                $p += strcspn($body, "\r\n", $p);
            } elseif ($c === '<' && ($body[$p + 1] ?? '') === '<') {
                $out .= '<<';
                $p += 2;
            } else {
                $bytes = $c === '(' ? $this->literal($body, $p) : $this->hex($body, $p);
                $out .= '<'.bin2hex($this->decryptBytes($bytes, $this->stringMethod, $num, $gen)).'>';
            }
        }

        return $out;
    }

    private static function name(array $dict, string $key): ?string
    {
        $value = $dict['v'][$key] ?? null;

        return ($value['t'] ?? null) === 'name' ? $value['v'] : null;
    }

    private static function num(array $dict, string $key): int|float|null
    {
        $value = $dict['v'][$key] ?? null;

        return ($value['t'] ?? null) === 'num' ? $value['v'] : null;
    }
}
