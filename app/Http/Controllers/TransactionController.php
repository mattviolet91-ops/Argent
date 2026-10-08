<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Http\Controllers\Concerns\ResolvesPeriod;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Mouvements : liste filtrée, ajout rapide (dépense, revenu, virement), modification. */
class TransactionController extends Controller
{
    use ReadsMoneyInput, ResolvesPeriod;

    public const TYPES = [
        'expense' => 'Dépense',
        'income' => 'Revenu',
        'transfer' => 'Virement entre comptes',
    ];

    public function index(Request $request): View
    {
        $scope = $this->scope($request);
        [$period, $from, $to] = $this->period($request, 'mois');
        $account = $request->integer('compte') ?: null;
        $category = (string) $request->query('categorie', '');
        $type = (string) $request->query('type', '');
        $search = trim((string) $request->query('q', '')) ?: null;

        $query = MoneyTransaction::query()->inScope($scope)->betweenDates($from, $to)
            ->when($account, fn (Builder $q) => $q->where('account_id', $account))
            ->when($category === 'aucune', fn (Builder $q) => $q->whereNull('category_id')->real())
            ->when(ctype_digit($category), fn (Builder $q) => $q->where('category_id', (int) $category))
            ->when(isset(self::TYPES[$type]), fn (Builder $q) => $q->where('kind', $type))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('label', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%')));

        $sums = (clone $query)->real()
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as income')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) as expense')
            ->first();

        return view('transactions.index', [
            'scope' => $scope,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'filters' => ['compte' => $account, 'categorie' => $category, 'type' => $type, 'q' => $search],
            'transactions' => $query->with(['account', 'category'])->latest('occurred_on')->latest('id')->paginate(60)->withQueryString(),
            'income' => (int) $sums->income,
            'expense' => (int) $sums->expense,
            'accountOptions' => $this->accountOptions(),
            'categoryOptions' => $this->categoryOptions(),
            'allAccounts' => MoneyAccount::query()->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')],
            'to_account_id' => ['nullable', 'required_if:type,transfer', 'integer', 'different:account_id', Rule::exists('money_accounts', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('money_categories', 'id')],
            'label' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
            'occurred_on' => ['required', 'date'],
        ], [
            'to_account_id.required_if' => 'Choisissez le compte qui reçoit l\'argent.',
            'to_account_id.different' => 'Choisissez deux comptes différents.',
        ], ['account_id' => 'compte', 'occurred_on' => 'date', 'label' => 'libellé']);
        $amount = $this->amount($request, 'amount');

        if ($data['type'] === 'transfer') {
            $this->transfer($data, $amount);
            app(MoneyAlertService::class)->check();

            return back()->with('status', 'Virement enregistré.');
        }

        $category = $this->category($data['category_id'] ?? null, $data['type']);
        $signed = $data['type'] === 'expense' ? -$amount : $amount;
        MoneyTransaction::query()->create([
            'account_id' => $data['account_id'],
            'occurred_on' => $data['occurred_on'],
            'amount' => $signed,
            'kind' => MoneyTransaction::kindFor($signed),
            'category_id' => $category?->id,
            'label' => trim((string) ($data['label'] ?? '')) ?: ($category?->name ?? self::TYPES[$data['type']]),
            'notes' => $data['notes'] ?? null,
            'source' => 'manual',
        ]);

        app(MoneyAlertService::class)->check();

        return back()->with('status', $data['type'] === 'expense' ? 'Dépense ajoutée.' : 'Revenu ajouté.');
    }

    public function edit(MoneyTransaction $transaction): View
    {
        return view('transactions.edit', [
            'transaction' => $transaction->load(['account', 'category']),
            'peer' => $this->peer($transaction),
            'accountOptions' => MoneyAccount::query()->ordered()->get(),
            'categoryOptions' => $this->categoryOptions(),
        ]);
    }

    public function update(Request $request, MoneyTransaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('money_categories', 'id')],
            'label' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
            'account_id' => ['sometimes', 'integer', Rule::exists('money_accounts', 'id')],
            'occurred_on' => ['sometimes', 'date'],
        ], [], ['label' => 'libellé']);

        // Paiement ou frais de l'app de devis : seuls la catégorie, le libellé et la note se changent ici.
        if ($transaction->source === 'devis') {
            $category = $this->category($data['category_id'] ?? null, MoneyTransaction::kindFor($transaction->amount));
            $transaction->update(['category_id' => $category?->id, 'label' => $data['label'], 'notes' => $data['notes'] ?? null]);
            app(MoneyAlertService::class)->check();

            return redirect()->route('transactions.index')->with('status', 'Mouvement modifié.');
        }

        $amount = $this->amount($request, 'amount');
        if ($transaction->isTransfer()) {
            DB::transaction(function () use ($transaction, $data, $amount) {
                foreach ([$transaction, $this->peer($transaction)] as $row) {
                    $row?->update([
                        'amount' => $row->amount < 0 ? -$amount : $amount,
                        'occurred_on' => $data['occurred_on'] ?? $row->occurred_on,
                        'label' => $data['label'],
                        'notes' => $data['notes'] ?? null,
                    ]);
                }
            });

            app(MoneyAlertService::class)->check();

            return redirect()->route('transactions.index')->with('status', 'Virement modifié.');
        }

        $type = $request->input('type') === 'income' ? 'income' : 'expense';
        $signed = $type === 'expense' ? -$amount : $amount;
        $category = $this->category($data['category_id'] ?? null, $type);
        $transaction->update([
            'account_id' => $data['account_id'] ?? $transaction->account_id,
            'occurred_on' => $data['occurred_on'] ?? $transaction->occurred_on,
            'amount' => $signed,
            'kind' => MoneyTransaction::kindFor($signed),
            'category_id' => $category?->id,
            'label' => $data['label'],
            'notes' => $data['notes'] ?? null,
        ]);

        app(MoneyAlertService::class)->check();

        return redirect()->route('transactions.index')->with('status', 'Mouvement modifié.');
    }

    public function destroy(MoneyTransaction $transaction): RedirectResponse
    {
        if ($transaction->source === 'devis') {
            return back()->withErrors(['transaction' => 'Ce mouvement vient de l\'app de devis : supprimez le paiement ou le frais là-bas, il disparaîtra ici à la prochaine mise à jour.']);
        }
        DB::transaction(function () use ($transaction) {
            $this->peer($transaction)?->delete();
            $transaction->delete();
        });

        app(MoneyAlertService::class)->check();

        return redirect()->route('transactions.index')->with('status', 'Mouvement supprimé.');
    }

    /** @param  array<string, mixed>  $data */
    private function transfer(array $data, int $amount): void
    {
        $from = MoneyAccount::query()->findOrFail($data['account_id']);
        $to = MoneyAccount::query()->findOrFail($data['to_account_id']);
        $key = (string) Str::uuid();
        $label = trim((string) ($data['label'] ?? ''));

        DB::transaction(function () use ($from, $to, $key, $label, $amount, $data) {
            foreach ([[$from, -$amount, 'Virement vers '.$to->name], [$to, $amount, 'Virement depuis '.$from->name]] as [$account, $value, $default]) {
                MoneyTransaction::query()->create([
                    'account_id' => $account->id,
                    'occurred_on' => $data['occurred_on'],
                    'amount' => $value,
                    'kind' => 'transfer',
                    'label' => $label ?: $default,
                    'notes' => $data['notes'] ?? null,
                    'transfer_key' => $key,
                    'source' => 'manual',
                ]);
            }
        });
    }

    /** L'autre moitié d'un virement. */
    private function peer(MoneyTransaction $transaction): ?MoneyTransaction
    {
        return $transaction->transfer_key
            ? MoneyTransaction::query()->where('transfer_key', $transaction->transfer_key)->whereKeyNot($transaction->id)->first()
            : null;
    }

    /** Catégorie choisie, si elle correspond au type (dépense ou revenu). */
    private function category(mixed $id, string $type): ?MoneyCategory
    {
        if (! $id) {
            return null;
        }
        $category = MoneyCategory::query()->find((int) $id);
        if ($category && $category->type !== $type) {
            throw ValidationException::withMessages(['category_id' => $type === 'expense' ? 'Choisissez une catégorie de dépense.' : 'Choisissez une catégorie de revenu.']);
        }

        return $category;
    }
}
