<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyLoanEntry;
use App\Models\MoneyPerson;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * « Qui me doit quoi » : l'argent prêté ou avancé (ami, client, associé…), ce qui a
 * été emprunté, et les remboursements. Le mouvement peut aussi être noté sur un
 * compte : il n'est alors compté ni comme gagné ni comme dépensé.
 */
class LoanController extends Controller
{
    use ReadsMoneyInput;

    public function index(): View
    {
        $people = MoneyPerson::query()->withSum('entries', 'amount')->orderBy('name')->get();
        $open = $people->whereNull('archived_at')->filter(fn (MoneyPerson $p) => $p->balance() !== 0)
            ->sortByDesc(fn (MoneyPerson $p) => [$p->isOverdue(), abs($p->balance())])->values();

        return view('loans.index', [
            'open' => $open,
            'settled' => $people->filter(fn (MoneyPerson $p) => $p->archived_at || $p->balance() === 0)->values(),
            'owedToMe' => (int) $open->filter(fn (MoneyPerson $p) => $p->balance() > 0)->sum(fn (MoneyPerson $p) => $p->balance()),
            'iOwe' => (int) -$open->filter(fn (MoneyPerson $p) => $p->balance() < 0)->sum(fn (MoneyPerson $p) => $p->balance()),
            'names' => $people->whereNull('archived_at')->pluck('name'),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    /** Nouvelle ligne : la personne est créée si c'est la première fois. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'relation' => ['nullable', Rule::in(array_keys(MoneyPerson::RELATIONS))],
            'due_on' => ['nullable', 'date'],
        ], [], ['name' => 'nom', 'due_on' => 'date de remboursement']);
        $entry = $this->entryData($request);
        $name = trim($data['name']);

        $person = DB::transaction(function () use ($data, $entry, $name) {
            $person = MoneyPerson::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? MoneyPerson::query()->create(['name' => $name, 'relation' => $data['relation'] ?? 'ami']);
            $person->update(['archived_at' => null] + (empty($data['due_on']) ? [] : ['due_on' => $data['due_on']]));
            $this->record($person, $entry);

            return $person;
        });
        app(MoneyAlertService::class)->check();

        return redirect()->route('loans.show', $person)->with('status', $this->message($person->fresh()));
    }

    public function show(MoneyPerson $person): View
    {
        return view('loans.show', [
            'person' => $person,
            'balance' => $person->balance(),
            'entries' => $person->entries()->with('transaction.account')->latest('occurred_on')->latest('id')->get(),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    /** Ajouter un prêt, une avance ou un remboursement à une personne. */
    public function entry(Request $request, MoneyPerson $person): RedirectResponse
    {
        $entry = $this->entryData($request);
        DB::transaction(fn () => $this->record($person, $entry));
        if ($person->archived_at) {
            $person->update(['archived_at' => null]);
        }
        app(MoneyAlertService::class)->check();

        return redirect()->route('loans.show', $person)->with('status', $this->message($person->fresh()));
    }

    public function destroyEntry(MoneyLoanEntry $entry): RedirectResponse
    {
        DB::transaction(function () use ($entry) {
            $entry->transaction?->delete();
            $entry->delete();
        });
        app(MoneyAlertService::class)->check();

        return redirect()->route('loans.show', $entry->person_id)->with('status', 'Ligne supprimée'.($entry->transaction_id ? ' (et son mouvement sur le compte).' : '.'));
    }

    public function update(Request $request, MoneyPerson $person): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'relation' => ['required', Rule::in(array_keys(MoneyPerson::RELATIONS))],
            'due_on' => ['nullable', 'date'],
            'person_notes' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'nom', 'due_on' => 'date de remboursement', 'person_notes' => 'note']);
        $data['notes'] = $data['person_notes'] ?? null;
        unset($data['person_notes']);
        $data['archived_at'] = $request->boolean('archived') ? ($person->archived_at ?? now()) : null;
        $person->update($data);
        app(MoneyAlertService::class)->check();

        return redirect()->route('loans.show', $person)->with('status', 'Enregistré.');
    }

    public function destroy(MoneyPerson $person): RedirectResponse
    {
        $person->delete();

        return redirect()->route('loans.index')->with('status', 'Supprimé (les mouvements notés sur vos comptes restent).');
    }

    /** @return array{type: string, amount: int, occurred_on: string, note: ?string, account_id: ?int} */
    private function entryData(Request $request): array
    {
        // Noms à part : les erreurs ne doivent pas rouvrir l'ajout rapide (type, amount…).
        $data = $request->validate([
            'loan_type' => ['required', Rule::in(array_keys(MoneyLoanEntry::TYPES))],
            'loan_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:160'],
            'loan_account' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
        ], [], ['loan_type' => 'type', 'loan_on' => 'date', 'loan_account' => 'compte']);

        return [
            'type' => $data['loan_type'],
            'amount' => $this->amount($request, 'loan_amount'),
            'occurred_on' => $data['loan_on'],
            'note' => $data['note'] ?? null,
            'account_id' => $data['loan_account'] ?? null,
        ];
    }

    /** @param  array{type: string, amount: int, occurred_on: string, note: ?string, account_id: ?int}  $entry */
    private function record(MoneyPerson $person, array $entry): MoneyLoanEntry
    {
        $transaction = null;
        if ($entry['account_id']) {
            $verb = [
                'pret' => 'Prêt à ', 'rembourse' => 'Remboursement de ',
                'emprunt' => 'Emprunt à ', 'je_rembourse' => 'Remboursement à ',
            ][$entry['type']];
            // Ni gagné ni dépensé : de l'argent qui sort pour revenir (ou l'inverse).
            $transaction = MoneyTransaction::query()->create([
                'account_id' => $entry['account_id'],
                'occurred_on' => $entry['occurred_on'],
                'amount' => MoneyLoanEntry::accountAmount($entry['type'], $entry['amount']),
                'kind' => 'transfer',
                'label' => mb_substr($verb.$person->name, 0, 160),
                'notes' => $entry['note'],
                'source' => 'loan',
            ]);
        }

        return $person->entries()->create([
            'occurred_on' => $entry['occurred_on'],
            'amount' => MoneyLoanEntry::TYPES[$entry['type']][1] * $entry['amount'],
            'type' => $entry['type'],
            'note' => $entry['note'],
            'transaction_id' => $transaction?->id,
        ]);
    }

    private function message(MoneyPerson $person): string
    {
        $balance = $person->balance();

        return match (true) {
            $balance > 0 => 'Enregistré. '.$person->name.' vous doit '.Money::plain($balance).'.',
            $balance < 0 => 'Enregistré. Vous devez '.Money::plain(-$balance).' à '.$person->name.'.',
            default => 'Enregistré. Vous êtes quittes avec '.$person->name.' 👍',
        };
    }
}
