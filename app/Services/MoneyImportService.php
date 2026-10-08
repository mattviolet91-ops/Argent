<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyRule;
use App\Models\MoneyTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Import d'un relevé bancaire : fichier CSV (export Excel de la banque) ou OFX.
 * Les colonnes sont reconnues toutes seules (date, libellé, montant ou débit/crédit),
 * les lignes déjà importées sont écartées, celles qui ressemblent à un mouvement
 * déjà saisi (paiement d'un devis, dépense fixe…) sont décochées, et la catégorie
 * est devinée grâce aux règles apprises lors des imports précédents.
 */
class MoneyImportService
{
    public const MAX_ROWS = 2000;

    /** Mots des libellés bancaires qui ne disent rien du commerçant. */
    private const NOISE = [
        'prlv', 'sepa', 'prelevement', 'carte', 'cb', 'vir', 'virement', 'recu', 'emis', 'inst', 'instantane', 'paiement', 'achat',
        'facture', 'retrait', 'dab', 'europeen', 'faveur', 'de', 'du', 'des', 'la', 'le', 'les', 'en', 'par', 'pour', 'ref', 'mandat',
        'ech', 'date', 'web', 'avoir', 'remise', 'cheque', 'chq', 'operation', 'sur', 'mr', 'mme', 'm', 'ste', 'sas', 'sarl',
    ];

    /**
     * Lit le fichier. Retourne les lignes [date Y-m-d, libellé, montant en centimes, identifiant banque].
     *
     * @return list<array{date: string, label: string, amount: int, fitid: ?string}>
     */
    public function parse(string $content): array
    {
        $content = $this->toUtf8($content);

        $rows = (str_contains($content, '<STMTTRN>') || str_contains(strtoupper(substr($content, 0, 400)), 'OFXHEADER'))
            ? $this->parseOfx($content)
            : $this->parseCsv($content);

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    /**
     * Prépare l'aperçu : doublons, ressemblances et catégories proposées.
     *
     * @param  list<array{date: string, label: string, amount: int, fitid: ?string}>  $rows
     * @return list<array{date: string, label: string, amount: int, hash: string, status: string, category_id: ?int, similar: ?string}>
     */
    public function preview(MoneyAccount $account, array $rows): array
    {
        $hashes = array_map(fn ($row) => $this->hash($account, $row), $rows);
        $known = MoneyTransaction::query()->whereIn('import_hash', $hashes)->pluck('import_hash')->flip();
        $rules = $this->rules();

        if ($rows !== []) {
            $dates = array_column($rows, 'date');
            $nearby = MoneyTransaction::query()->where('account_id', $account->id)->where('source', '!=', 'import')
                ->whereDate('occurred_on', '>=', Carbon::parse(min($dates))->subDays(5))
                ->whereDate('occurred_on', '<=', Carbon::parse(max($dates))->addDays(5))
                ->get(['id', 'occurred_on', 'amount', 'label']);
        } else {
            $nearby = collect();
        }
        $used = [];

        $preview = [];
        foreach ($rows as $i => $row) {
            $similar = null;
            $status = isset($known[$hashes[$i]]) ? 'known' : 'new';
            if ($status === 'new') {
                $match = $nearby->first(fn (MoneyTransaction $t) => ! isset($used[$t->id]) && $t->amount === $row['amount']
                    && abs($t->occurred_on->diffInDays(Carbon::parse($row['date']), false)) <= 5);
                if ($match) {
                    $used[$match->id] = true;
                    $status = 'similar';
                    $similar = $match->label.' ('.$match->occurred_on->format('d/m').')';
                }
            }
            $preview[] = [
                'date' => $row['date'],
                'label' => $row['label'],
                'amount' => $row['amount'],
                'hash' => $hashes[$i],
                'status' => $status,
                'category_id' => $this->guess($row['label'], $rules),
                'similar' => $similar,
            ];
        }

        return $preview;
    }

    /** Retient « mot du libellé → catégorie » pour les prochains imports. */
    public function learn(string $label, int $categoryId): void
    {
        $keyword = $this->keyword($label);
        if ($keyword !== null) {
            MoneyRule::query()->updateOrCreate(['keyword' => $keyword], ['category_id' => $categoryId]);
        }
    }

    /** Mot-clé du commerçant : « CB CARREFOUR MARKET 05/10 » → « carrefour market ». */
    public function keyword(string $label): ?string
    {
        $words = array_values(array_filter(
            preg_split('/[^a-z]+/', Str::of($label)->ascii()->lower()->value()) ?: [],
            fn ($word) => strlen($word) >= 2 && ! in_array($word, self::NOISE, true) && ! preg_match('/^x+$/', $word),
        ));
        $keyword = implode(' ', array_slice($words, 0, 2));

        return strlen($keyword) >= 3 ? substr($keyword, 0, 60) : null;
    }

    /** @param  Collection<int, MoneyRule>  $rules */
    public function guess(string $label, Collection $rules): ?int
    {
        $normalized = ' '.implode(' ', preg_split('/[^a-z]+/', Str::of($label)->ascii()->lower()->value()) ?: []).' ';
        foreach ($rules as $rule) {
            if (str_contains($normalized, ' '.$rule->keyword.' ')) {
                return $rule->category_id;
            }
        }

        return null;
    }

    /** Règles, les plus précises (mots-clés les plus longs) d'abord. */
    public function rules(): Collection
    {
        return MoneyRule::query()->get()->sortByDesc(fn (MoneyRule $rule) => strlen($rule->keyword))->values();
    }

    /** @param  array{date: string, label: string, amount: int, fitid?: ?string}  $row */
    public function hash(MoneyAccount $account, array $row): string
    {
        $key = ! empty($row['fitid'])
            ? 'fitid|'.$row['fitid']
            : $row['date'].'|'.$row['amount'].'|'.Str::of($row['label'])->ascii()->lower()->squish()->value();

        return hash('sha256', $account->id.'|'.$key);
    }

    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** @return list<array{date: string, label: string, amount: int, fitid: ?string}> */
    private function parseOfx(string $content): array
    {
        $rows = [];
        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $content, $blocks);
        foreach ($blocks[1] as $block) {
            $field = function (string $name) use ($block): ?string {
                return preg_match('/<'.$name.'>([^<\r\n]*)/i', $block, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : null;
            };
            $date = $field('DTPOSTED');
            $amount = Money::parse(str_replace(',', '.', (string) $field('TRNAMT')));
            if (! $date || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', $date, $d) || $amount === null || $amount === 0) {
                continue;
            }
            $label = trim(implode(' ', array_unique(array_filter([$field('NAME'), $field('MEMO')]))));
            $rows[] = [
                'date' => sprintf('%s-%s-%s', $d[1], $d[2], $d[3]),
                'label' => mb_substr($label !== '' ? $label : 'Opération', 0, 160),
                'amount' => $amount,
                'fitid' => $field('FITID') ?: null,
            ];
        }

        return $rows;
    }

    /** @return list<array{date: string, label: string, amount: int, fitid: ?string}> */
    private function parseCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        $sample = implode("\n", array_slice($lines, 0, 30));
        $delimiter = collect([';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t"), ',' => substr_count($sample, ',') / 3])
            ->sortDesc()->keys()->first();
        $table = array_map(fn ($line) => array_map(fn ($cell) => trim((string) $cell), str_getcsv($line, $delimiter, '"', '')), $lines);

        // Ligne d'en-tête : la première qui nomme une date et un montant (les banques ajoutent parfois quelques lignes avant).
        $header = null;
        $columns = null;
        foreach (array_slice($table, 0, 30, true) as $index => $cells) {
            $columns = $this->columns($cells);
            if ($columns['date'] !== null && ($columns['amount'] !== null || $columns['debit'] !== null || $columns['credit'] !== null)) {
                $header = $index;
                break;
            }
        }
        if ($header === null) {
            return [];
        }

        $rows = [];
        foreach (array_slice($table, $header + 1) as $cells) {
            $date = $this->date($cells[$columns['date']] ?? '');
            if ($date === null) {
                continue;
            }
            if ($columns['amount'] !== null) {
                $amount = $this->amount($cells[$columns['amount']] ?? '');
            } else {
                $debit = $this->amount($cells[$columns['debit']] ?? '');
                $credit = $this->amount($cells[$columns['credit']] ?? '');
                $amount = ($credit !== null ? abs($credit) : 0) - ($debit !== null ? abs($debit) : 0);
            }
            if (! $amount) {
                continue;
            }
            $label = collect($columns['labels'])->map(fn ($i) => $cells[$i] ?? '')->filter()->unique()->implode(' · ');
            $rows[] = [
                'date' => $date,
                'label' => mb_substr(Str::squish($label) ?: 'Opération', 0, 160),
                'amount' => $amount,
                'fitid' => null,
            ];
        }

        return $rows;
    }

    /**
     * Repère les colonnes d'après leur titre.
     *
     * @param  list<string>  $cells
     * @return array{date: ?int, amount: ?int, debit: ?int, credit: ?int, labels: list<int>}
     */
    private function columns(array $cells): array
    {
        $found = ['date' => null, 'amount' => null, 'debit' => null, 'credit' => null, 'labels' => []];
        $dateScore = 0;
        foreach ($cells as $i => $cell) {
            $name = Str::of($cell)->ascii()->lower()->replaceMatches('/[^a-z ]/', ' ')->squish()->value();
            if ($name === '') {
                continue;
            }
            if (str_starts_with($name, 'date') || $name === 'jour') {
                // Date d'opération plutôt que date de valeur.
                $score = str_contains($name, 'valeur') ? 1 : (str_contains($name, 'operation') || $name === 'date' ? 3 : 2);
                if ($score > $dateScore) {
                    [$found['date'], $dateScore] = [$i, $score];
                }
            } elseif (str_starts_with($name, 'debit')) {
                $found['debit'] ??= $i;
            } elseif (str_starts_with($name, 'credit')) {
                $found['credit'] ??= $i;
            } elseif (str_starts_with($name, 'montant') || str_starts_with($name, 'amount') || $name === 'somme') {
                $found['amount'] ??= $i;
            } elseif (preg_match('/^(libelle|description|intitule|detail|nature|operation|commentaire|beneficiaire|tiers|memo)/', $name)) {
                $found['labels'][] = $i;
            }
        }

        return $found;
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['d/m/Y', 'j/n/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'd-m-y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** « -1 234,56 », « 1.234,56 », « +12.50 EUR » → centimes. */
    private function amount(string $value): ?int
    {
        $value = str_replace(['EUR', '€', '+', ' ', "\u{00A0}", "\u{202F}", '−', '–'], ['', '', '', '', '', '', '-', '-'], trim($value));
        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            $value = '-'.$m[1];
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace('.', '', $value) : str_replace(',', '', $value);
        }

        return Money::parse($value);
    }
}
