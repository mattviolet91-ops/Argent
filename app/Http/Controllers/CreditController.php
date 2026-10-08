<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyCategory;
use App\Models\MoneyCredit;
use App\Models\MoneyRecurring;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Crédits : à partir du capital, du taux, de la durée (ou de la mensualité) et de la
 * date de la 1re échéance, l'app calcule le capital restant, la date de fin, les
 * intérêts restants et le tableau d'amortissement.
 */
class CreditController extends Controller
{
    use ReadsMoneyInput;

    public function index(): View
    {
        $credits = MoneyCredit::query()->orderByRaw('archived_at IS NOT NULL')->orderBy('first_due_on')->get();
        $active = $credits->filter(fn (MoneyCredit $c) => ! $c->archived_at && $c->remaining() > 0)->values();

        return view('credits.index', [
            'active' => $active,
            'done' => $credits->diff($active)->values(),
            'remaining' => (int) $active->sum(fn (MoneyCredit $c) => $c->remaining()),
            'monthly' => (int) $active->sum(fn (MoneyCredit $c) => $c->monthly + $c->insurance),
            'interest' => (int) $active->sum(fn (MoneyCredit $c) => $c->remainingInterest()),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credit = MoneyCredit::query()->create($this->validated($request));

        return redirect()->route('credits.show', $credit)->with('status', 'Crédit ajouté.');
    }

    public function show(MoneyCredit $credit): View
    {
        $schedule = $credit->schedule();
        $paid = $credit->paidCount();
        $years = collect($schedule)->groupBy(fn ($row) => $row['date']->year)->map(fn ($rows, $year) => [
            'year' => $year,
            'payment' => (int) $rows->sum('payment'),
            'interest' => (int) $rows->sum('interest'),
            'capital' => (int) $rows->sum('capital'),
            'remaining' => (int) $rows->last()['remaining'],
        ])->values();

        return view('credits.show', [
            'credit' => $credit->load(['account', 'recurring']),
            'paid' => $paid,
            'remaining' => $credit->remaining(),
            'next' => array_slice($schedule, $paid, 12),
            'years' => $years,
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function update(Request $request, MoneyCredit $credit): RedirectResponse
    {
        $data = $this->validated($request);
        $data['archived_at'] = $request->boolean('archived') ? ($credit->archived_at ?? now()) : null;
        $credit->update($data);
        // La mensualité dans les Fixes suit les changements.
        $credit->recurring?->update(['amount' => -($credit->monthly + $credit->insurance), 'active' => ! $credit->archived_at && $credit->remaining() > 0]);

        return redirect()->route('credits.show', $credit)->with('status', 'Crédit enregistré.');
    }

    public function destroy(MoneyCredit $credit): RedirectResponse
    {
        $credit->delete();

        return redirect()->route('credits.index')->with('status', 'Crédit supprimé'.($credit->recurring_id ? ' (sa mensualité reste dans les Fixes).' : '.'));
    }

    /** La mensualité devient une dépense fixe, notée toute seule chaque mois. */
    public function recurring(MoneyCredit $credit): RedirectResponse
    {
        abort_if($credit->recurring_id && $credit->recurring, 404);
        if (! $credit->account_id) {
            throw ValidationException::withMessages(['credit_account' => 'Choisissez d\'abord le compte qui paie la mensualité (Modifier).']);
        }
        $paid = $credit->paidCount();
        if ($paid >= $credit->months) {
            throw ValidationException::withMessages(['credit_account' => 'Ce crédit est déjà remboursé.']);
        }
        $recurring = MoneyRecurring::query()->create([
            'label' => mb_substr('Crédit · '.$credit->name, 0, 160),
            'amount' => -($credit->monthly + $credit->insurance),
            'account_id' => $credit->account_id,
            'category_id' => MoneyCategory::query()->where('type', 'expense')->where('name', 'like', '%crédit%')->value('id'),
            'frequency' => 'mensuel',
            'next_on' => $credit->dueDate($paid + 1)->toDateString(),
            'active' => true,
        ]);
        $credit->update(['recurring_id' => $recurring->id]);

        return redirect()->route('credits.show', $credit)->with('status', 'Mensualité ajoutée aux Fixes : prochaine le '.$recurring->next_on->format('d/m/Y').'.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'first_due_on' => ['required', 'date', 'after:2000-01-01'],
            'months' => ['nullable', 'integer', 'min:1', 'max:480'],
            'credit_account' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
            'credit_notes' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'nom', 'first_due_on' => '1re mensualité', 'months' => 'durée', 'credit_notes' => 'note', 'credit_account' => 'compte']);
        $principal = $this->amount($request, 'principal');
        $rate = $this->rate($request);
        $insurance = $this->amount($request, 'insurance', required: false) ?? 0;
        $monthly = $this->amount($request, 'monthly', required: false);
        $months = $data['months'] ?? null;

        if ($months) {
            // Durée connue : la mensualité se calcule (sauf si elle est donnée).
            $monthly ??= MoneyCredit::payment($principal, $rate, (int) $months);
        } elseif ($monthly) {
            $months = MoneyCredit::duration($principal, $rate, $monthly);
            if ($months === null || $months > 480) {
                throw ValidationException::withMessages(['monthly' => 'Cette mensualité ne suffit pas à rembourser le crédit : vérifiez-la, ou indiquez la durée.']);
            }
        } else {
            throw ValidationException::withMessages(['months' => 'Indiquez la durée (en mois) ou la mensualité.']);
        }

        return [
            'name' => $data['name'],
            'principal' => $principal,
            'rate' => $rate,
            'months' => (int) $months,
            'monthly' => $monthly,
            'insurance' => $insurance,
            'first_due_on' => Carbon::parse($data['first_due_on'])->toDateString(),
            'account_id' => $data['credit_account'] ?? null,
            'notes' => $data['credit_notes'] ?? null,
        ];
    }

    /** « 3,45 » (%) → 345 ; vide → 0. */
    private function rate(Request $request): int
    {
        $value = trim(str_replace('%', '', (string) $request->input('rate', '')));
        if ($value === '') {
            return 0;
        }
        $rate = Money::parse($value);
        if ($rate === null || $rate < 0 || $rate > 3000) {
            throw ValidationException::withMessages(['rate' => 'Taux invalide (ex. 3,45).']);
        }

        return $rate;
    }
}
