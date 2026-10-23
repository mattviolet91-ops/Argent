<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyRule;
use App\Models\MoneyScopeRule;
use App\Models\MoneyTransaction;
use App\Support\Money;
use App\Support\PdfDecryptor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Smalot\PdfParser\Header as PdfHeader;
use Smalot\PdfParser\Page as PdfPage;
use Smalot\PdfParser\Parser as PdfParser;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\XObject\Form as PdfForm;

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
    public function parse(string $content, string $password = ''): array
    {
        $this->pdfIssue = null;
        $this->pdfReport = [];
        // Relevé PDF (celui envoyé par la banque chaque mois).
        if (str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF')) {
            return array_slice($this->parsePdf($content, $password), 0, self::MAX_ROWS);
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
    private const PDF_SKIP = '/^\s*(ancien |nouveau )?solde\b|^\s*(total|totaux|sous[- ]total)\s*(des|du|de|operations|mouvements|debits?|credits?|periode|mensuel|:|$)|^\s*(report|a reporter|reporte)\b|\bsolde (crediteur|debiteur|au|precedent|en votre faveur|initial|final)\b|\btotal des (operations|mouvements)\b/';

    /** Mois en lettres (« 5 oct. », « 05 OCTOBRE 2026 »). */
    private const MONTH_PATTERN = '(janv?|f[ée]vr?|mars?|avr|mai|juin|juil|ao[uû]t?|sept?|oct|nov|d[ée]c)[a-zéû]*\.?';

    /**
     * Pourquoi le dernier relevé PDF n'a rien donné : password (mot de passe à l'ouverture),
     * unreadable (fichier abîmé), no_text (scan, photo), layout (lignes non reconnues).
     */
    public ?string $pdfIssue = null;

    /**
     * Ce qui a été vu dans le dernier relevé PDF, sans montant ni libellé (pour le dépannage).
     *
     * @var array<string, mixed>
     */
    public array $pdfReport = [];

    /**
     * Relevé PDF : les lignes qui commencent par une date et finissent par un montant. Le
     * sens (sortie ou entrée) vient de la colonne Débit / Crédit, sinon du signe, sinon du
     * libellé (« VIR SEPA RECU », « REMISE »…) : à vérifier dans l'aperçu. Les relevés
     * protégés (chiffrés, sans mot de passe pour les ouvrir) sont d'abord déchiffrés.
     *
     * @return list<array{date: string, label: string, amount: int, fitid: null, guessed: bool}>
     */
    private function parsePdf(string $content, string $password = ''): array
    {
        $this->pdfReport = ['protection' => PdfDecryptor::isEncrypted($content) ? PdfDecryptor::describe($content) : null];
        if ($this->pdfReport['protection'] !== null) {
            $decrypted = PdfDecryptor::decrypt($content, $password);
            if ($decrypted === null) {
                $this->pdfIssue = 'password';

                return [];
            }
            $content = $decrypted;
        }
        try {
            $pdf = (new PdfParser)->parseContent($content);
            $pages = $pdf->getPages();
        } catch (\Throwable) {
            $this->pdfIssue = 'unreadable';

            return [];
        }
        $details = $pdf->getDetails();
        $this->pdfReport += [
            'producer' => Str::limit(trim(($details['Producer'] ?? '').' / '.($details['Creator'] ?? ''), ' /'), 80) ?: null,
            'pages' => count($pages),
        ];

        // Le texte, ligne par ligne (même hauteur sur la page), de gauche à droite.
        $lines = [];
        $text = '';
        $pieces = 0;
        foreach ($pages as $pageIndex => $page) {
            $rows = [];
            // Texte de la page, et des blocs qu'elle affiche (certains relevés y mettent tout le tableau).
            $data = [];
            foreach ([$page, ...$this->pdfFormPages($page)] as $source) {
                try {
                    $data = [...$data, ...$source->getDataTm()];
                } catch (\Throwable) {
                    // Bloc illisible : ignoré.
                }
            }
            foreach ($data as $item) {
                $value = trim(preg_replace('/[ \t\r\n]+/', ' ', (string) ($item[1] ?? '')) ?? '');
                if ($value === '') {
                    continue;
                }
                $pieces++;
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
                $lines[] = ['y' => (float) $y, 'page' => $pageIndex, 'items' => $this->splitPdfItems($this->mergePdfGlyphs($items))];
            }
        }
        // Texte entier (il comprend aussi les blocs que la lecture ligne par ligne ne voit pas).
        try {
            $fullText = $pdf->getText();
        } catch (\Throwable) {
            $fullText = '';
        }
        $this->pdfReport['pieces'] = $pieces;
        $this->pdfReport['characters'] = mb_strlen(trim($fullText.$text));
        if ($this->pdfReport['characters'] < 20) {
            $this->pdfIssue = 'no_text';

            return [];
        }

        // Année des dates « 05/10 » : celle de la fin du relevé (« … au 31/10/2026 »).
        $end = null;
        if (preg_match_all('/\b(\d{2})[\/.](\d{2})[\/.](\d{4})\b/', $text.' '.$fullText, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                if (checkdate((int) $match[2], (int) $match[1], (int) $match[3]) && (int) $match[3] >= 2000 && (int) $match[3] <= today()->year + 1) {
                    $date = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
                    $end = max($end ?? $date, $date);
                }
            }
        }
        $endDate = $end ? Carbon::parse($end) : today();

        $rows = $this->pdfRowsFromLines($lines, $endDate);
        // Peu ou rien trouvé ligne par ligne : lecture du texte entier.
        $fromText = $this->pdfRowsFromText($fullText, $endDate);
        if (count($fromText) > max(1.5 * count($rows), count($rows) + 2)) {
            $rows = $fromText;
        }
        if ($rows === []) {
            $this->pdfIssue = 'layout';
            $this->pdfReport['shapes'] = $this->pdfShapes($lines, $fullText);
        }

        return $rows;
    }

    /**
     * Blocs de texte (« Form XObject ») qu'affiche une page, relus comme des pages à part :
     * un relevé ré-imprimé en PDF (iPhone : Partager → Imprimer) met souvent tout dedans.
     *
     * @return list<PdfPage>
     */
    private function pdfFormPages(PdfPage $page): array
    {
        try {
            $forms = [];
            foreach ($page->getXObjects() as $form) {
                if ($form instanceof PdfForm) {
                    $forms[spl_object_id($form)] = $form;
                }
            }
            $pages = [];
            foreach ($forms as $form) {
                $document = $form->getDocument();
                $content = new PDFObject($document, new PdfHeader([], $document), (string) $form->getContent(), $form->getConfig());
                $pages[] = new PdfPage($document, new PdfHeader(['Contents' => $content, 'Resources' => $form->get('Resources')], $document), null, $form->getConfig());
            }

            return $pages;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Opérations d'après la position des textes sur la page (colonnes Débit / Crédit…).
     *
     * @param  list<array{y: float, page: int, items: list<array{0: float, 1: string}>}>  $lines
     * @return list<array{date: string, label: string, amount: int, fitid: null, guessed: bool}>
     */
    private function pdfRowsFromLines(array $lines, Carbon $endDate): array
    {
        $columns = null;
        $result = [];
        $orphans = [];
        $dated = 0;
        foreach ($lines as $line) {
            $items = $line['items'];
            $date = $this->takePdfDate($items);
            if ($date === null) {
                $plain = Str::of(implode(' ', array_column($items, 1)))->ascii()->lower()->value();
                if ($this->pdfAmountIndex($items, 0) === null) {
                    // En-tête du tableau : où sont les colonnes Débit et Crédit.
                    if ($found = $this->pdfColumns($items)) {
                        $columns = $found;
                        $this->pdfReport['header'] = true;

                        continue;
                    }
                    // Morceau de libellé sur sa propre ligne : rattaché ensuite à l'opération la plus proche.
                    if (! preg_match(self::PDF_SKIP, $plain)) {
                        $orphans[] = ['y' => $line['y'], 'x' => $items[0][0], 'page' => $line['page'], 'text' => implode(' ', array_column($items, 1))];
                    }
                }

                continue;
            }
            $dated++;
            [$day, $month, $year, $items] = $date;
            // Deuxième date (date de valeur) : ignorée.
            if ($second = $this->takePdfDate($items)) {
                $items = $second[3];
            }
            $amountIndex = $this->pdfAmountIndex($items, 0);
            if ($amountIndex === null) {
                continue;
            }
            $amountItem = $items[$amountIndex];
            $labelItems = array_slice($items, 0, $amountIndex);
            // Date de valeur placée après le libellé (« … 03.10 45,20 ») : ignorée aussi.
            while ($labelItems !== [] && preg_match('/^\d{1,2}[\/.\-]\d{1,2}(?:[\/.\-]\d{2,4})?$/', end($labelItems)[1])) {
                array_pop($labelItems);
            }
            $label = trim(implode(' ', array_column($labelItems, 1)));
            $plain = ' '.Str::of($label)->ascii()->lower()->value().' ';
            if (preg_match(self::PDF_SKIP, $plain)) {
                continue;
            }
            $cents = abs((int) $this->amount($amountItem[1]));
            $full = $this->pdfDate($day, $month, $year, $endDate);
            if ($cents === 0 || $full === null) {
                continue;
            }
            $result[] = [
                'date' => $full, 'before' => [], 'label' => $label, 'after' => [],
                'raw' => $amountItem[1], 'center' => $this->pdfCenter($amountItem), 'columns' => $columns,
                'y' => $line['y'], 'page' => $line['page'], 'x' => $labelItems !== [] ? $labelItems[0][0] : null,
            ];
        }
        $this->pdfReport['dated_lines'] = $dated;

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
            if ($label === '') {
                continue;
            }
            $sign = $this->pdfSign($row['raw'], $label);
            if ($sign === null && $row['columns']) {
                $sign = abs($row['center'] - $row['columns']['credit']) < abs($row['center'] - $row['columns']['debit']) ? 1 : -1;
            }
            $rows[] = ['date' => $row['date'], 'label' => $label, 'raw' => $row['raw'], 'sign' => $sign];
        }

        return $this->pdfSignedRows($rows);
    }

    /**
     * Montants sans signe : si le relevé marque ses sorties d'un « − », ce sont des entrées ;
     * sinon le libellé décide (pdfRow).
     *
     * @param  list<array{date: string, label: string, raw: string, sign: ?int}>  $rows
     * @return list<array{date: string, label: string, amount: int, fitid: null, guessed: bool}>
     */
    private function pdfSignedRows(array $rows): array
    {
        $marked = in_array(-1, array_column($rows, 'sign'), true) && ! in_array(1, array_column($rows, 'sign'), true);

        return array_map(fn ($row) => $this->pdfRow($row['date'], $row['label'], $row['raw'], $row['sign'] ?? ($marked ? 1 : null)), $rows);
    }

    /**
     * Opérations d'après le texte entier, ligne après ligne : une date ouvre une opération,
     * le premier montant la ferme (le libellé peut tenir sur plusieurs lignes).
     *
     * @return list<array{date: string, label: string, amount: int, fitid: null, guessed: bool}>
     */
    private function pdfRowsFromText(string $text, Carbon $endDate): array
    {
        $rows = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\t+| {2,}/u', $line) ?: []), fn ($p) => $p !== ''));
            if ($parts === []) {
                continue;
            }
            $items = $this->splitPdfItems(array_map(fn ($part, $i) => [(float) $i * 100, $part], $parts, array_keys($parts)));
            if ($date = $this->takePdfDate($items)) {
                [$day, $month, $year, $items] = $date;
                if ($second = $this->takePdfDate($items)) {
                    $items = $second[3];
                }
                $current = ['date' => $this->pdfDate($day, $month, $year, $endDate), 'label' => []];
            } elseif ($current === null) {
                continue;
            }
            foreach ($items as $item) {
                if ($this->isPdfAmount($item[1])) {
                    $label = Str::squish(implode(' ', $current['label']));
                    $plain = ' '.Str::of($label)->ascii()->lower()->value().' ';
                    if ($current['date'] && $label !== '' && ! preg_match(self::PDF_SKIP, $plain) && $this->amount($item[1])) {
                        $rows[] = ['date' => $current['date'], 'label' => $label, 'raw' => $item[1], 'sign' => $this->pdfSign($item[1], $label)];
                    }
                    $current = null;
                    break;
                }
                if (! preg_match('/^\d{1,2}[\/.\-]\d{1,2}(?:[\/.\-]\d{2,4})?$/', $item[1]) && count($current['label']) < 4) {
                    $current['label'][] = $item[1];
                }
            }
        }

        return $this->pdfSignedRows($rows);
    }

    /** @return array{date: string, label: string, amount: int, fitid: null, guessed: bool} */
    private function pdfRow(string $date, string $label, string $raw, ?int $sign): array
    {
        return [
            'date' => $date,
            'label' => mb_substr($label, 0, 160),
            'amount' => ($sign ?? $this->pdfWordsSign($label)) * abs((int) $this->amount($raw)),
            'fitid' => null,
            'guessed' => true,
        ];
    }

    /** Sens écrit sur le montant (« −45,20 », « + 1 200,00 », « 45,20- ») ; null s'il n'y en a pas. */
    private function pdfSign(string $raw, string $label): ?int
    {
        $raw = trim($raw);
        if (preg_match('/^[-−–]|-$/u', $raw)) {
            return -1;
        }

        return str_starts_with($raw, '+') ? 1 : null;
    }

    /** Sens d'après le libellé : une sortie, sauf « VIR SEPA RECU », « REMISE »… */
    private function pdfWordsSign(string $label): int
    {
        $plain = ' '.Str::of($label)->ascii()->lower()->value().' ';
        foreach (self::CREDIT_WORDS as $word) {
            if (str_contains($plain, $word)) {
                return 1;
            }
        }

        return -1;
    }

    /** Date complète ; l'année manquante est celle de la fin du relevé (l'année d'avant pour décembre sur un relevé de janvier). */
    private function pdfDate(int $day, int $month, ?int $year, Carbon $endDate): ?string
    {
        if ($year === null) {
            $year = $endDate->year - ($month > $endDate->month + 1 ? 1 : 0);
        } elseif ($year < 100) {
            $year += 2000;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * Date en tête de ligne : « 05/10 », « 5.10.26 », « 05/10/2026 », « 5 oct. », « 05 OCTOBRE 2026 ».
     *
     * @param  list<array{0: float, 1: string}>  $items
     * @return array{0: int, 1: int, 2: ?int, 3: list<array{0: float, 1: string}>}|null jour, mois, année, reste de la ligne
     */
    private function takePdfDate(array $items): ?array
    {
        if ($items === []) {
            return null;
        }
        // Les premiers morceaux bout à bout (« 5 » « oct. » « 2026 » peuvent être séparés).
        $joined = '';
        $bounds = [];
        foreach (array_slice($items, 0, 3) as $i => $item) {
            $start = $joined === '' ? 0 : strlen($joined) + 1;
            $joined .= ($joined === '' ? '' : ' ').$item[1];
            $bounds[$i] = [$start, strlen($joined)];
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{4}|\d{2}))?(?=\s|$)/u', $joined, $m)) {
            $month = (int) $m[2];
        } elseif (preg_match('/^(\d{1,2})(?:er)?\s*'.self::MONTH_PATTERN.'(?:\s+(\d{4}))?(?=\s|$)/iu', $joined, $m)) {
            $month = self::monthNumber($m[2]);
            $m[3] = $m[3] ?? '';
        } else {
            return null;
        }
        if ($month < 1 || $month > 12 || (int) $m[1] < 1 || (int) $m[1] > 31) {
            return null;
        }
        $used = strlen($m[0]);
        $rest = [];
        foreach ($items as $i => $item) {
            [$start, $end] = $bounds[$i] ?? [PHP_INT_MAX, PHP_INT_MAX];
            if ($end <= $used) {
                continue;
            }
            if ($start < $used) {
                $remainder = trim(substr($item[1], $used - $start));
                if ($remainder !== '') {
                    $rest[] = [$item[0] + ($used - $start) * 5, $remainder];
                }

                continue;
            }
            $rest[] = $item;
        }

        return [(int) $m[1], $month, ($m[3] ?? '') !== '' ? (int) $m[3] : null, $rest];
    }

    private static function monthNumber(string $name): int
    {
        $name = Str::of($name)->ascii()->lower()->value();
        if (str_starts_with($name, 'jui')) {
            return str_starts_with($name, 'juil') ? 7 : 6;
        }

        return ['jan' => 1, 'fev' => 2, 'mar' => 3, 'avr' => 4, 'mai' => 5, 'aou' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12][substr($name, 0, 3)] ?? 0;
    }

    /**
     * En-tête du tableau : position des colonnes des sorties et des entrées
     * (« Débit / Crédit », « Sorties / Entrées », « Retraits / Dépôts »…).
     *
     * @param  list<array{0: float, 1: string}>  $items
     * @return array{debit: float, credit: float}|null
     */
    private function pdfColumns(array $items): ?array
    {
        $debit = $credit = null;
        foreach ($items as $item) {
            $text = Str::of($item[1])->ascii()->lower()->value();
            // Un morceau peut contenir plusieurs titres (« Débit Crédit ») : position estimée de chaque mot.
            foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as [$word, $at]) {
                $center = $item[0] + ($at + strlen($word) / 2) * 5;
                if (preg_match('/^(debits?|sorties?|retraits?|sortant|depenses?)\b/', $word)) {
                    $debit ??= $center;
                } elseif (preg_match('/^(credits?|entrees?|depots?|entrant|recettes?)\b/', $word)) {
                    $credit ??= $center;
                }
            }
        }

        return $debit !== null && $credit !== null ? ['debit' => $debit, 'credit' => $credit] : null;
    }

    /**
     * Texte écrit lettre par lettre (certains relevés) : les lettres proches sont remises en mots.
     *
     * @param  list<array{0: float, 1: string}>  $items
     * @return list<array{0: float, 1: string}>
     */
    private function mergePdfGlyphs(array $items): array
    {
        $count = count($items);
        $singles = count(array_filter($items, fn ($item) => mb_strlen($item[1]) === 1));
        if ($count < 6 || $singles < $count * 0.6) {
            return $items;
        }
        $advances = [];
        for ($i = 1; $i < $count; $i++) {
            $gap = $items[$i][0] - $items[$i - 1][0];
            if ($gap > 0) {
                $advances[] = $gap / max(1, mb_strlen($items[$i - 1][1]));
            }
        }
        sort($advances);
        $advance = $advances[intdiv(count($advances), 2)] ?? 5.0;
        $out = [];
        $end = null;
        foreach ($items as $item) {
            $gap = $end === null ? INF : $item[0] - $end;
            if ($gap <= $advance * 0.6) {
                $out[count($out) - 1][1] .= $item[1];
            } elseif ($gap <= $advance * 2.2) {
                $out[count($out) - 1][1] .= ' '.$item[1];
            } else {
                $out[] = $item;
            }
            $end = $item[0] + mb_strlen($item[1]) * $advance;
        }

        return $out;
    }

    /**
     * Morceaux de texte collés (« 02/10 CB CARREFOUR », « CARREFOUR 45,20 ») : séparés ;
     * signe seul devant un montant (« − » « 45,20 ») : rattaché.
     *
     * @param  list<array{0: float, 1: string}>  $items
     * @return list<array{0: float, 1: string}>
     */
    private function splitPdfItems(array $items): array
    {
        $out = [];
        foreach ($items as $index => [$x, $value]) {
            if ($index === 0 && preg_match('/^(\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?)\s+(.+)$/u', $value, $m)) {
                $out[] = [$x, $m[1]];
                $value = $m[2];
                $x += 30;
                if (preg_match('/^(\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?)\s+(.+)$/u', $value, $n)) {
                    $out[] = [$x, $n[1]];
                    $value = $n[2];
                    $x += 30;
                }
            }
            if (preg_match('/^(.*\D)\s+((?:[+\-−]\s?)?\d{1,3}(?:[ .\x{00A0}\x{202F}]\d{3})*,\d{2}\s*(?:€|EUR)?-?)$/u', $value, $m) && ! $this->isPdfAmount($value) && ! preg_match('/[+\-−]$/u', trim($m[1]))) {
                $out[] = [$x, trim($m[1])];
                $out[] = [$x + 6 * mb_strlen($m[1]), $m[2]];

                continue;
            }
            $out[] = [$x, $value];
        }
        // « − » ou « + » seul, puis le montant.
        $merged = [];
        foreach ($out as $item) {
            $last = end($merged);
            if ($last !== false && in_array($last[1], ['-', '+', '−', '–'], true) && $this->isPdfAmount($item[1])) {
                $merged[count($merged) - 1] = [$last[0], $last[1].$item[1]];

                continue;
            }
            $merged[] = $item;
        }

        return $merged;
    }

    private function isPdfAmount(string $value): bool
    {
        return (bool) preg_match('/^(?:[+\-−–]\s?)?\d{1,3}(?:[ .\x{00A0}\x{202F}]?\d{3})*,\d{2}\s*(?:€|EUR)?\s*-?$/u', trim($value));
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

    /**
     * Allure des premières lignes, sans les données : lettres → « a », chiffres → « 9 »
     * (« 99/99 aaa aaaaaaaa | 99,99 »), pour comprendre un relevé non reconnu.
     *
     * @param  list<array{y: float, page: int, items: list<array{0: float, 1: string}>}>  $lines
     * @return list<string>
     */
    private function pdfShapes(array $lines, string $fullText): array
    {
        $mask = fn (string $s) => mb_substr(preg_replace(['/\p{L}/u', '/\d/'], ['a', '9'], $s) ?? '', 0, 90);
        $shapes = [];
        foreach ($lines as $line) {
            $shape = implode(' | ', array_map(fn ($item) => $mask($item[1]), $line['items']));
            if (preg_match('/9/', $shape)) {
                $shapes[] = $shape;
            }
            if (count($shapes) >= 12) {
                break;
            }
        }
        if ($shapes === []) {
            foreach (array_slice(array_filter(preg_split('/\R/u', $fullText) ?: [], fn ($l) => preg_match('/\d/', $l)), 0, 12) as $line) {
                $shapes[] = $mask(str_replace("\t", ' | ', $line));
            }
        }

        return $shapes;
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
        // « (45,20) » et « 45,20- » : négatifs.
        if (preg_match('/^\((.*)\)$/', $value, $m) || preg_match('/^([^-].*)-$/', $value, $m)) {
            $value = '-'.$m[1];
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace('.', '', $value) : str_replace(',', '', $value);
        }

        return Money::parse($value);
    }
}
