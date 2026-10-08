<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Services\MoneySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Dépenses et revenus fixes (loyer, abonnements, salaire…), ajoutés tout seuls à leur date. */
class RecurringController extends Controller
{
    use ReadsMoneyInput;

    public function index(): View
    {
        $recurrings = MoneyRecurring::query()->with(['account', 'category'])->orderByDesc('active')->orderBy('next_on')->get();
        $active = $recurrings->where('active', true);

        return view('recurrings.index', [
            'recurrings' => $recurrings,
            'monthlyOut' => (int) -$active->filter(fn ($r) => $r->amount < 0)->sum(fn ($r) => $r->monthlyAmount()),
            'monthlyIn' => (int) $active->filter(fn ($r) => $r->amount > 0)->sum(fn ($r) => $r->monthlyAmount()),
            'accountOptions' => $this->accountOptions(),
            'categoryOptions' => $this->categoryOptions(),
        ]);
    }

    public function store(Request $request, MoneySyncService $sync): RedirectResponse
    {
        MoneyRecurring::query()->create($this->validated($request) + ['active' => true]);
        // Échéance déjà passée (ex. loyer du 1er saisi le 5) : ajoutée tout de suite.
        $sync->runRecurring();

        return redirect()->route('recurrings.index')->with('status', 'Ajouté. Il sera noté tout seul à chaque échéance.');
    }

    public function update(Request $request, MoneyRecurring $recurring, MoneySyncService $sync): RedirectResponse
    {
        if ($request->has('toggle')) {
            $recurring->update(['active' => ! $recurring->active]);

            return redirect()->route('recurrings.index')->with('status', $recurring->active ? 'Réactivé.' : 'Mis en pause.');
        }
        $recurring->update($this->validated($request));
        $sync->runRecurring();

        return redirect()->route('recurrings.index')->with('status', 'Enregistré.');
    }

    public function destroy(MoneyRecurring $recurring): RedirectResponse
    {
        $recurring->delete();

        return redirect()->route('recurrings.index')->with('status', 'Supprimé (les mouvements déjà notés restent).');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['expense', 'income'])],
            'account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('money_categories', 'id')],
            'frequency' => ['required', Rule::in(array_keys(MoneyRecurring::FREQUENCIES))],
            'next_on' => ['required', 'date', 'after_or_equal:'.today()->subYear()->toDateString()],
        ], ['next_on.after_or_equal' => 'Choisissez une date de moins d\'un an.'], ['label' => 'libellé', 'next_on' => 'prochaine date']);
        $amount = $this->amount($request, 'amount');
        if (! empty($data['category_id']) && MoneyCategory::query()->whereKey($data['category_id'])->value('type') !== $data['type']) {
            throw ValidationException::withMessages(['category_id' => $data['type'] === 'expense' ? 'Choisissez une catégorie de dépense.' : 'Choisissez une catégorie de revenu.']);
        }
        $data['amount'] = $data['type'] === 'expense' ? -$amount : $amount;
        unset($data['type']);

        return $data;
    }
}
