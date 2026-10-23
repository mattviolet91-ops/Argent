<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyTag;
use App\Models\MoneyTransaction;
use App\Services\ActivityLogger;
use App\Services\MoneyAlertService;
use App\Services\MoneyImportService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Import d'un relevé bancaire en 2 temps : aperçu (lignes reconnues, doublons écartés,
 * catégories proposées), puis import des lignes cochées. Le fichier lu n'est gardé
 * que le temps de l'aperçu, dans le dossier privé.
 */
class ImportController extends Controller
{
    use ReadsMoneyInput;

    private const SESSION_KEY = 'argent.import';

    public function __construct(private readonly MoneyImportService $importer) {}

    public function create(): View
    {
        return view('import.create', ['accountOptions' => $this->accountOptions()]);
    }

    public function preview(Request $request): RedirectResponse
    {
        // Un ou plusieurs relevés d'un coup (CSV, OFX ou PDF).
        $files = array_values(array_filter(array_merge(
            $request->hasFile('files') ? (array) $request->file('files') : [],
            $request->hasFile('file') ? [$request->file('file')] : [],
        )));
        $request->merge(['files_count' => count($files)]);
        $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')],
            'files_count' => ['integer', 'min:1', 'max:24'],
            'files.*' => ['file', 'max:8192', 'mimes:csv,txt,ofx,qfx,xml,pdf'],
            'file' => ['nullable', 'file', 'max:8192', 'mimes:csv,txt,ofx,qfx,xml,pdf'],
            'pdf_password' => ['nullable', 'string', 'max:100'],
        ], [
            'files_count.min' => 'Choisissez le fichier du relevé.',
            'files_count.max' => '24 fichiers au plus à la fois.',
            'files.*.mimes' => 'Les relevés doivent être en PDF, CSV (export Excel de la banque) ou OFX.',
            'file.mimes' => 'Les relevés doivent être en PDF, CSV (export Excel de la banque) ou OFX.',
            'files.*.max' => 'Fichier trop lourd (8 Mo au plus).',
        ], ['account_id' => 'compte']);

        // Plusieurs relevés qui se recouvrent : une opération n'est gardée qu'une fois
        // (autant de fois qu'elle apparaît dans un même fichier, pas la somme des fichiers).
        $account = MoneyAccount::query()->findOrFail((int) $request->input('account_id'));
        $rows = [];
        $kept = [];
        $unread = [];
        $reports = [];
        foreach ($files as $number => $file) {
            $parsed = $this->importer->parse((string) file_get_contents($file->getRealPath()), (string) $request->input('pdf_password', ''));
            if ($parsed === []) {
                $issue = $this->importer->pdfIssue ?? 'empty';
                $unread[] = $file->getClientOriginalName().' ('.self::ISSUES[$issue].')';
                $reports[] = self::report($number + 1, $issue, $this->importer->pdfReport);
            }
            $counts = [];
            foreach ($parsed as $row) {
                $hash = $this->importer->hash($account, $row);
                $counts[$hash] = ($counts[$hash] ?? 0) + 1;
                if ($counts[$hash] > ($kept[$hash] ?? 0)) {
                    $kept[$hash] = $counts[$hash];
                    $rows[] = $row;
                }
            }
        }
        if ($rows === []) {
            $issues = array_unique(array_column($reports, 'issue'));
            $request->session()->flash('import_report', implode("\n\n", array_column($reports, 'text')));
            $request->session()->flash('import_issue', $issues[0] ?? 'empty');
            throw ValidationException::withMessages(['file' => count($issues) === 1 ? self::HELP[$issues[0]] : self::HELP['empty']]);
        }
        // Plusieurs relevés : remis dans l'ordre des dates (un seul : l'ordre du fichier).
        if (count($files) > 1) {
            usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));
        }
        $rows = array_slice($rows, 0, MoneyImportService::MAX_ROWS);

        $this->forget($request);
        $path = 'argent-imports/'.Str::random(32).'.json';
        Storage::disk('local')->put($path, json_encode(['account_id' => $account->id, 'rows' => $rows, 'unread' => $unread]));
        $request->session()->put(self::SESSION_KEY, $path);

        return redirect()->route('import.show');
    }

    /** Pourquoi un fichier n'a rien donné (en bref, dans la liste des fichiers). */
    private const ISSUES = [
        'password' => 'demande un mot de passe',
        'unreadable' => 'fichier abîmé',
        'no_text' => 'image sans texte',
        'layout' => 'lignes non reconnues',
        'empty' => 'aucune opération trouvée',
    ];

    /** Que faire, selon le problème. */
    private const HELP = [
        'password' => 'Ce relevé demande un mot de passe pour s\'ouvrir : tapez-le dans « Le PDF demande un mot de passe ? » (il ne sert qu\'à le lire, il n\'est pas gardé).',
        'unreadable' => 'Ce fichier PDF n\'a pas pu être ouvert (abîmé, ou pas vraiment un PDF). Téléchargez-le à nouveau depuis l\'app ou le site de la banque.',
        'no_text' => 'Ce PDF ne contient qu\'une image (scan, photo ou capture d\'écran) : il n\'y a aucun texte à lire. Prenez le relevé PDF de la banque (dans son app ou sur son site, rubrique Relevés / Documents), ou l\'export CSV des opérations.',
        'layout' => 'Le texte du relevé est bien lu, mais ses lignes d\'opérations ne sont pas reconnues. Copiez les « détails techniques » ci-dessous (ni montant ni libellé dedans) et envoyez-les pour que ce format soit ajouté. En attendant : export CSV des opérations depuis le site de la banque.',
        'empty' => 'Aucune opération reconnue. Prenez le relevé PDF de la banque (pas une photo ou un scan), ou exportez les opérations en CSV (ou OFX) depuis l\'espace en ligne de la banque.',
    ];

    /**
     * Détails d'un fichier non lu, sans montant ni libellé : de quoi comprendre un format de relevé.
     *
     * @param  array<string, mixed>  $seen
     * @return array{issue: string, text: string}
     */
    private static function report(int $number, string $issue, array $seen): array
    {
        $lines = [
            'Fichier '.$number.' : '.$issue,
            'Protection : '.($seen['protection'] ?? 'aucune'),
            'Logiciel : '.($seen['producer'] ?? '?'),
            'Pages : '.($seen['pages'] ?? '?').' · morceaux de texte : '.($seen['pieces'] ?? '?').' · caractères : '.($seen['characters'] ?? '?')
                .' · lignes datées : '.($seen['dated_lines'] ?? '?').' · en-tête Débit/Crédit : '.(! empty($seen['header']) ? 'oui' : 'non'),
        ];
        if (! empty($seen['shapes'])) {
            $lines[] = 'Allure des lignes :';
            array_push($lines, ...$seen['shapes']);
        }

        return ['issue' => $issue, 'text' => implode("\n", $lines)];
    }

    public function show(Request $request): View|RedirectResponse
    {
        $data = $this->pending($request);
        if (! $data) {
            return redirect()->route('import.create');
        }
        $account = MoneyAccount::query()->findOrFail($data['account_id']);
        $rows = $this->importer->preview($account, $data['rows']);

        return view('import.show', [
            'account' => $account,
            'rows' => $rows,
            'counts' => collect($rows)->countBy('status'),
            'categoryOptions' => $this->categoryOptions(),
            'unread' => (array) ($data['unread'] ?? []),
            'guessed' => collect($rows)->contains('guessed', true),
            'tagNames' => MoneyTag::query()->whereNull('archived_at')->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->pending($request);
        if (! $data) {
            return redirect()->route('import.create')->withErrors(['file' => 'Aperçu expiré : choisissez à nouveau le fichier.']);
        }
        $account = MoneyAccount::query()->findOrFail($data['account_id']);
        $rows = $this->importer->preview($account, $data['rows']);
        $selected = array_map('intval', array_keys((array) $request->input('import', [])));
        $chosen = (array) $request->input('category', []);
        $scopes = (array) $request->input('scope', []);
        $flips = (array) $request->input('flip', []);
        $tags = (array) $request->input('tag', []);
        $categories = MoneyCategory::query()->pluck('type', 'id');

        $created = 0;
        $tagged = 0;
        $changes = [];
        DB::transaction(function () use ($rows, $selected, $chosen, $scopes, $flips, $tags, $categories, $account, &$created, &$tagged, &$changes) {
            foreach ($selected as $index) {
                $row = $rows[$index] ?? null;
                if (! $row || $row['status'] === 'known') {
                    continue;
                }
                // Relevé PDF : le sens (entrée / sortie) deviné peut être inversé dans l'aperçu.
                if (! empty($row['guessed']) && ! empty($flips[$index])) {
                    $row['amount'] = -$row['amount'];
                }
                // Perso ou pro : gardé sur l'opération (toujours pour un compte mixte, sinon s'il diffère du compte).
                $scope = in_array($scopes[$index] ?? null, ['perso', 'pro'], true) ? $scopes[$index] : $row['scope'];
                $explicit = $account->mixed || $scope !== $account->scope;
                $categoryId = (int) ($chosen[$index] ?? 0) ?: null;
                // Catégorie de revenu sur une dépense (ou l'inverse) : ignorée.
                if ($categoryId && ($categories[$categoryId] ?? null) !== MoneyTransaction::kindFor($row['amount'])) {
                    $categoryId = null;
                }
                $transaction = MoneyTransaction::query()->create([
                    'account_id' => $account->id,
                    'occurred_on' => $row['date'],
                    'amount' => $row['amount'],
                    'kind' => MoneyTransaction::kindFor($row['amount']),
                    'category_id' => $categoryId,
                    'label' => $row['label'],
                    'source' => 'import',
                    'import_hash' => $row['hash'],
                    'recurring_id' => $row['status'] === 'price' ? $row['recurring_id'] : null,
                    'scope' => $explicit ? $scope : null,
                ]);
                if ($explicit) {
                    $this->importer->learnScope($row['label'], $scope);
                }
                // Chantier ou projet (créé s'il n'existe pas encore).
                $tagIds = is_string($tags[$index] ?? null) ? MoneyTag::idsFromInput(mb_substr($tags[$index], 0, 300)) : [];
                if ($tagIds !== []) {
                    $transaction->tags()->sync($tagIds);
                    $tagged++;
                }
                // Abonnement à un autre prix : il remplace le prélèvement prévu, et la dépense fixe prend le nouveau prix.
                if ($row['status'] === 'price') {
                    MoneyTransaction::query()->whereKey($row['replaces'])->where('source', 'recurring')->first()?->delete();
                    $recurring = MoneyRecurring::query()->find($row['recurring_id']);
                    if ($recurring && $recurring->amount !== $row['amount']) {
                        $changes[] = ['id' => $recurring->id, 'label' => $recurring->label, 'old' => $recurring->amount, 'new' => $row['amount'], 'on' => today()->toDateString()];
                        $recurring->update(['amount' => $row['amount']]);
                    }
                }
                if ($categoryId && $categoryId !== $row['category_id']) {
                    $this->importer->learn($row['label'], $categoryId);
                }
                $created++;
            }
        });
        $this->forget($request);
        if ($changes !== []) {
            $settings = app(Settings::class);
            $settings->set(['subscriptions.price_changes' => array_slice([...(array) $settings->get('subscriptions.price_changes', []), ...$changes], -20)]);
        }
        app(MoneyAlertService::class)->check();
        ActivityLogger::log('argent.import', 'Relevé importé dans l\'app Argent ('.$created.' opération(s))');

        return redirect()->route('transactions.index', ['compte' => $account->id, 'periode' => 'tout'])
            ->with('status', $created.' opération'.($created > 1 ? 's importées' : ' importée').'.'
                .($tagged ? ' '.$tagged.' mise'.($tagged > 1 ? 's' : '').' sur un chantier.' : '')
                .($changes ? ' Nouveau prix pour '.implode(', ', array_map(fn ($c) => '« '.$c['label'].' »', $changes)).' : les Fixes sont à jour.' : ''));
    }

    /** @return array{account_id: int, rows: list<array{date: string, label: string, amount: int, fitid: ?string}>}|null */
    private function pending(Request $request): ?array
    {
        $path = (string) $request->session()->get(self::SESSION_KEY, '');
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $data = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($data) && isset($data['account_id'], $data['rows']) ? $data : null;
    }

    private function forget(Request $request): void
    {
        $path = (string) $request->session()->pull(self::SESSION_KEY, '');
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
