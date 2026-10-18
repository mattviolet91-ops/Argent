<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyRule;
use App\Models\MoneyScopeRule;
use App\Models\MoneyTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;

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
     * Lit le fichier (CSV, OFX ou PDF). Retourne les lignes [date Y-m-d, libellé, montant en
     * centimes, identifiant banque] ; « guessed » : sens (entrée ou sortie) deviné, d'un PDF.
     *
     * @return list<array{date: string, label: string, amount: int, fitid: ?string, guessed?: bool}>
     */
    public function parse(string $content): array
    {
        // Relevé PDF (celui envoyé par la banque chaque mois).
        if (str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF')) {
            return array_slice($this->parsePdf($content), 0, self::MAX_ROWS);
        }
        $content = $this->toUtf8($content);

        $rows = (str_contains($content, '<STMTTRN>') || str_contains(strtoupper(substr($content, 0, 400)), 'OFXHEADER'))
            ? $this->parseOfx($content)
            : $this->parseCsv($content);

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    /**
     * Prépare l'aperçu : doublons, ressemblances, catégories et perso / pro proposés.
     * Statut « price » : prélèvement d'une dépense fixe arrivé à un autre prix (abonnement
     * plus cher…) ; il remplace le prélèvement prévu à l'ancien prix.
     *
     * @param  list<array{date: string, label: string, amount: int, fitid: ?string, guessed?: bool}>  $rows
     * @return list<array{date: string, label: string, amount: int, hash: string, status: string, category_id: ?int, similar: ?string, scope: string, scope_hint: string, guessed: bool, replaces?: int, recurring_id?: int, old_amount?: int}>
     */
    public function preview(MoneyAccount $account, array $rows): array
    {
        $hashes = array_map(fn ($row) => $this->hash($account, $row), $rows);
        $known = MoneyTransaction::query()->whereIn('import_hash', $hashes)->pluck('import_hash')->flip();
        $rules = $this->rules();
        $scopeRules = $this->scopeRules();
        $categoryScopes = MoneyCategory::query()->pluck('scope', 'id');

        if ($rows !== []) {
            $dates = array_column($rows, 'date');
            $nearby = MoneyTransaction::query()->where('account_id', $account->id)->where('source', '!=', 'import')
                ->whereDate('occurred_on', '>=', Carbon::parse(min($dates))->subDays(5))
                ->whereDate('occurred_on', '<=', Carbon::parse(max($dates))->addDays(5))
                ->get(['id', 'occurred_on', 'amount', 'label', 'source', 'recurring_id', 'category_id']);
        } else {
            $nearby = collect();
        }
        $used = [];
        $recurringKeywords = $this->recurringKeywords($nearby->where('source', 'recurring')->pluck('recurring_id')->filter()->unique()->all());

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
            $extra = [];
            // Même commerçant qu'une dépense fixe prévue ces jours-là, mais à un autre prix.
            if ($status === 'new' && $row['amount'] < 0 && ($keyword = $this->keyword($row['label']))) {
                $planned = $nearby->first(fn (MoneyTransaction $t) => ! isset($used[$t->id]) && $t->source === 'recurring' && $t->amount < 0
                    && $this->sameMerchant($keyword, $recurringKeywords[$t->recurring_id] ?? [])
                    && abs($t->occurred_on->diffInDays(Carbon::parse($row['date']), false)) <= 6
                    && $row['amount'] >= $t->amount * 2 && $row['amount'] <= $t->amount / 2);
                if ($planned) {
                    $used[$planned->id] = true;
                    $status = 'price';
                    $similar = '« '.$planned->label.' » était prévu à '.Money::format(-$planned->amount).' le '.$planned->occurred_on->format('d/m').' : nouveau prix, les Fixes seront mis à jour.';
                    $extra = ['replaces' => $planned->id, 'recurring_id' => $planned->recurring_id, 'old_amount' => $planned->amount, 'planned_category' => $planned->category_id];
                }
            }
            $categoryId = $this->guess($row['label'], $rules) ?? ($extra['planned_category'] ?? null);
            [$scope, $hint] = $this->guessScope($row['label'], $categoryId, $account, $scopeRules, $categoryScopes);
            $preview[] = [
                'date' => $row['date'],
                'label' => $row['label'],
                'amount' => $row['amount'],
                'hash' => $hashes[$i],
                'status' => $status,
                'category_id' => $categoryId,
                'similar' => $similar,
                'scope' => $scope,
                'scope_hint' => $hint,
                'guessed' => (bool) ($row['guessed'] ?? false),
            ] + $extra;
        }

        return $preview;
    }

    /**
     * Mots-clés de chaque dépense fixe : son nom, et les commerçants de ses prélèvements déjà importés.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function recurringKeywords(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (MoneyRecurring::query()->whereIn('id', $ids)->get(['id', 'label']) as $recurring) {
            $map[$recurring->id] = array_filter([$this->keyword($recurring->label)]);
        }
        foreach (MoneyTransaction::query()->whereIn('recurring_id', $ids)->where('source', 'import')->latest('occurred_on')->limit(200)->get(['recurring_id', 'label']) as $t) {
            $map[$t->recurring_id][] = $this->keyword($t->label);
        }

        return array_map(fn ($words) => array_values(array_unique(array_filter($words))), $map);
    }

    /** « netflix com » et « netflix » : même commerçant (même mot-clé, ou même premier mot). */
    private function sameMerchant(string $keyword, array $known): bool
    {
        $first = explode(' ', $keyword)[0];
        foreach ($known as $candidate) {
            if ($candidate === $keyword || (strlen($first) >= 3 && explode(' ', $candidate)[0] === $first)) {
                return true;
            }
        }

        return false;
    }

    /** Retient « mot du libellé → catégorie » pour les prochains imports. */
    public function learn(string $label, int $categoryId): void
    {
        $keyword = $this->keyword($label);
        if ($keyword !== null) {
            MoneyRule::query()->updateOrCreate(['keyword' => $keyword], ['category_id' => $categoryId]);
        }
    }

    /**
     * Perso ou pro ? D'abord ce qui a été appris (« point p » → pro), puis la catégorie
     * (Matériaux → pro, Courses → perso), sinon le compte. Retourne [perso|pro, rule|category|account].
     *
     * @param  Collection<int, MoneyScopeRule>  $rules
     * @param  Collection<int, string>  $categoryScopes
     * @return array{0: string, 1: string}
     */
    public function guessScope(string $label, ?int $categoryId, MoneyAccount $account, Collection $rules, Collection $categoryScopes): array
    {
        $normalized = ' '.implode(' ', preg_split('/[^a-z]+/', Str::of($label)->ascii()->lower()->value()) ?: []).' ';
        foreach ($rules as $rule) {
            if (str_contains($normalized, ' '.$rule->keyword.' ')) {
                return [$rule->scope, 'rule'];
            }
        }
        $fromCategory = $categoryId ? ($categoryScopes[$categoryId] ?? null) : null;
        if (in_array($fromCategory, ['perso', 'pro'], true)) {
            return [$fromCategory, 'category'];
        }

        return [$account->scope, 'account'];
    }

    /** Retient « mot du libellé → perso ou pro » pour les prochaines fois. */
    public function learnScope(string $label, string $scope): void
    {
        $keyword = $this->keyword($label);
        if ($keyword !== null && in_array($scope, ['perso', 'pro'], true)) {
            MoneyScopeRule::query()->updateOrCreate(['keyword' => $keyword], ['scope' => $scope]);
        }
    }

    /** Règles perso / pro, les plus précises d'abord. */
    public function scopeRules(): Collection
    {
        return MoneyScopeRule::query()->get()->sortByDesc(fn (MoneyScopeRule $rule) => strlen($rule->keyword))->values();
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

    /** Mots d'un libellé qui disent que l'argent arrive (quand la colonne ne le dit pas). */
    private const CREDIT_WORDS = [
        'vir sepa recu', 'virement recu', 'vir recu', 'vir inst recu', 'vir inst re', 'virement de', 'vir de ', 'remise', 'versement',
        'avoir', 'rembt', 'remboursement', 'prime', 'interets', 'depot', 'salaire', 'paie ', 'allocation', 'caf ', 'encaissement',
    ];

    /** Lignes d'un relevé qui ne sont pas des opérations. */
    private const PDF_SKIP = '/^\s*(ancien |nouveau )?(solde|total|totaux|sous total|report|a reporter|reporte)\b|\bsolde (crediteur|debiteur|au|precedent|en votre faveur)\b/';

    /**
     * Relevé PDF : les lignes qui commencent par une date et finissent par un montant. Le
     * sens (sortie ou entrée) vient de la colonne Débit / Crédit, sinon du signe, sinon du
     * libellé (« VIR SEPA RECU », « REMISE »…) : à vérifier dans l'aperçu.
     *
     * @return list<array{date: string, label: string, amount: int, fitid: null, guessed: bool}>
     */
    private function parsePdf(string $content): array
    {
        try {
            $pdf = (new PdfParser)->parseContent($content);
        } catch (\Throwable) {
            return [];
        }

        // Le texte, ligne par ligne (même hauteur sur la page), de gauche à droite.
        $lines = [];
        $text = '';
        foreach ($pdf->getPages() as $pageIndex => $page) {
            $rows = [];
            foreach ($page->getDataTm() as $item) {
                $value = trim(preg_replace('/[ \t\r\n]+/', ' ', (string) ($item[1] ?? '')) ?? '');
                if ($value === '') {
                    continue;
                }
                $y = (float) $item[0][5];
                $key = null;
                foreach (array_keys($rows) as $existing) {
                    if (abs((float) $existing - $y) <= 2.5) {
                        $key = $existing;
                        break;
                    }
                }
                $key ??= (string) $y;
                $rows[$key][] = [(float) $item[0][4], $value];
                $text .= ' '.$value;
            }
            uksort($rows, fn ($a, $b) => (float) $b <=> (float) $a);
            foreach ($rows as $y => $items) {
                usort($items, fn ($a, $b) => $a[0] <=> $b[0]);
                $lines[] = ['y' => (float) $y, 'page' => $pageIndex, 'items' => $this->splitPdfItems($items)];
            }
        }

        // Année des dates « 05/10 » : celle de la fin du relevé (« … au 31/10/2026 »).
        $end = null;
        if (preg_match_all('/\b(\d{2})[\/.](\d{2})[\/.](\d{4})\b/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                if (checkdate((int) $match[2], (int) $match[1], (int) $match[3]) && (int) $match[3] >= 2000) {
                    $date = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
                    $end = max($end ?? $date, $date);
                }
            }
        }
        $endDate = $end ? Carbon::parse($end) : today();

        $columns = null;
        $result = [];
        $orphans = [];
        foreach ($lines as $index => $line) {
            $words = array_map(fn ($item) => Str::of($item[1])->ascii()->lower()->value(), $line['items']);
            // En-tête du tableau : où sont les colonnes Débit et Crédit.
            $debit = $credit = null;
            foreach ($line['items'] as $i => $item) {
                if (preg_match('/^debit/', $words[$i])) {
                    $debit = $this->pdfCenter($item);
                } elseif (preg_match('/^credit/', $words[$i])) {
                    $credit = $this->pdfCenter($item);
                }
            }
            if ($debit !== null && $credit !== null) {
                $columns = ['debit' => $debit, 'credit' => $credit];

                continue;
            }

            $items = $line['items'];
            $first = $items[0][1] ?? '';
            if (! preg_match('/^(\d{2})[\/.](\d{2})(?:[\/.](\d{2}|\d{4}))?$/', $first, $d)) {
                // Morceau de libellé sur sa propre ligne : rattaché ensuite à l'opération la plus proche.
                if (! $this->pdfAmountIndex($items) && ! preg_match(self::PDF_SKIP, implode(' ', $words))) {
                    $orphans[] = ['y' => $line['y'], 'x' => $items[0][0], 'page' => $line['page'], 'text' => implode(' ', array_column($items, 1))];
                }

                continue;
            }
            array_shift($items);
            // Deuxième date (date de valeur) : ignorée.
            if (isset($items[0]) && preg_match('/^\d{2}[\/.]\d{2}(?:[\/.]\d{2,4})?$/', $items[0][1])) {
                array_shift($items);
            }
            $amountIndex = $this->pdfAmountIndex($items, 0);
            if ($amountIndex === null) {
                continue;
            }
            $amountItem = $items[$amountIndex];
            $label = trim(implode(' ', array_column(array_slice($items, 0, $amountIndex), 1)));
            $plain = ' '.Str::of($label)->ascii()->lower()->value().' ';
            if (preg_match(self::PDF_SKIP, $plain)) {
                continue;
            }

            $raw = $amountItem[1];
            $cents = abs((int) $this->amount(preg_replace('/\s*(€|eur)$/i', '', $raw) ?? $raw));
            if ($cents === 0) {
                continue;
            }
            $year = isset($d[3]) ? (strlen($d[3]) === 2 ? 2000 + (int) $d[3] : (int) $d[3]) : $endDate->year;
            if (! isset($d[3]) && (int) $d[2] > $endDate->month + 1) {
                $year--;
            }
            if (! checkdate((int) $d[2], (int) $d[1], $year)) {
                continue;
            }
            $result[] = [
                'date' => sprintf('%04d-%02d-%02d', $year, $d[2], $d[1]),
                'before' => [], 'label' => $label, 'after' => [],
                'raw' => $raw, 'center' => $this->pdfCenter($amountItem), 'columns' => $columns,
                'y' => $line['y'], 'page' => $line['page'], 'x' => $amountIndex > 0 ? $items[0][0] : null,
            ];
        }

        // Lignes de libellé seules : à l'opération la plus proche sur la page (celle du dessus si égalité).
        foreach ($orphans as $orphan) {
            $best = null;
            foreach ($result as $k => $row) {
                if ($row['page'] !== $orphan['page'] || ($row['x'] !== null && abs($row['x'] - $orphan['x']) > 40)) {
                    continue;
                }
                $distance = abs($row['y'] - $orphan['y']) + ($row['y'] < $orphan['y'] ? 3 : 0);
                if ($distance <= 16 && ($best === null || $distance < $best[1])) {
                    $best = [$k, $distance];
                }
            }
            if ($best !== null && count($result[$best[0]]['before']) + count($result[$best[0]]['after']) < 2) {
                $result[$best[0]][$result[$best[0]]['y'] < $orphan['y'] ? 'before' : 'after'][] = $orphan['text'];
            }
        }

        $rows = [];
        foreach ($result as $row) {
            $label = Str::squish(implode(' ', [...$row['before'], $row['label'], ...$row['after']]));
            $plain = ' '.Str::of($label)->ascii()->lower()->value().' ';
            if ($label === '') {
                continue;
            }
            // Sens : signe du montant, sinon colonne Débit / Crédit, sinon le libellé.
            $raw = $row['raw'];
            if (str_starts_with($raw, '-') || str_ends_with($raw, '-')) {
                $sign = -1;
            } elseif (str_starts_with($raw, '+')) {
                $sign = 1;
            } elseif ($row['columns']) {
                $sign = abs($row['center'] - $row['columns']['credit']) < abs($row['center'] - $row['columns']['debit']) ? 1 : -1;
            } else {
                $sign = -1;
                foreach (self::CREDIT_WORDS as $word) {
                    if (str_contains($plain, $word)) {
                        $sign = 1;
                        break;
                    }
                }
            }
            $rows[] = [
                'date' => $row['date'],
                'label' => mb_substr($label, 0, 160),
                'amount' => $sign * abs((int) $this->amount(preg_replace('/\s*(€|eur)$/i', '', $raw) ?? $raw)),
                'fitid' => null,
                'guessed' => true,
            ];
        }

        return $rows;
    }

    /**
     * Morceaux de texte collés (« 02/10 CB CARREFOUR », « CARREFOUR 45,20 ») : séparés.
     *
     * @param  list<array{0: float, 1: string}>  $items
     * @return list<array{0: float, 1: string}>
     */
    private function splitPdfItems(array $items): array
    {
        $out = [];
        foreach ($items as $index => [$x, $value]) {
            if ($index === 0 && preg_match('/^(\d{2}[\/.]\d{2}(?:[\/.]\d{2,4})?)\s+(.+)$/u', $value, $m)) {
                $out[] = [$x, $m[1]];
                $value = $m[2];
                $x += 30;
                if (preg_match('/^(\d{2}[\/.]\d{2}(?:[\/.]\d{2,4})?)\s+(.+)$/u', $value, $n)) {
                    $out[] = [$x, $n[1]];
                    $value = $n[2];
                    $x += 30;
                }
            }
            if (preg_match('/^(.*\D)\s+([+-]?\d{1,3}(?:[ .\x{00A0}\x{202F}]\d{3})*,\d{2}\s*(?:€|EUR)?)$/u', $value, $m) && ! $this->isPdfAmount($value)) {
                $out[] = [$x, trim($m[1])];
                $out[] = [$x + 6 * mb_strlen($m[1]), $m[2]];

                continue;
            }
            $out[] = [$x, $value];
        }

        return $out;
    }

    private function isPdfAmount(string $value): bool
    {
        return (bool) preg_match('/^[+-]?\d{1,3}(?:[ .\x{00A0}\x{202F}]?\d{3})*,\d{2}\s*(?:€|EUR)?-?$/u', $value);
    }

    /**
     * Premier montant de la ligne (le suivant, s'il y en a un, est souvent le solde).
     *
     * @param  list<array{0: float, 1: string}>  $items
     */
    private function pdfAmountIndex(array $items, int $from = 1): ?int
    {
        foreach ($items as $i => $item) {
            if ($i >= $from && $this->isPdfAmount($item[1])) {
                return $i;
            }
        }

        return null;
    }

    /** Milieu approximatif d'un morceau de texte (5 points par caractère). */
    private function pdfCenter(array $item): float
    {
        return $item[0] + mb_strlen($item[1]) * 2.5;
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
