<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use App\Services\MoneySubscriptionService;
use App\Services\MoneySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Dépenses et revenus fixes (loyer, abonnements, salaire…), ajoutés tout seuls à leur date. */
class RecurringController extends Controller
{
    use ReadsMoneyInput;

    public function index(MoneySubscriptionService $subscriptions): View
    {
        $recurrings = MoneyRecurring::query()->with(['account', 'toAccount', 'category'])->orderByDesc('active')->orderBy('next_on')->get();
        $active = $recurrings->where('active', true);

        return view('recurrings.index', [
            'recurrings' => $recurrings,
            'monthlyOut' => (int) -$active->filter(fn ($r) => ! $r->isTransfer() && $r->amount < 0)->sum(fn ($r) => $r->monthlyAmount()),
            'monthlyIn' => (int) $active->filter(fn ($r) => ! $r->isTransfer() && $r->amount > 0)->sum(fn ($r) => $r->monthlyAmount()),
            'monthlySaved' => (int) $active->filter(fn ($r) => $r->isTransfer())->sum(fn ($r) => $r->monthlyAmount()),
            'suggestions' => $subscriptions->suggestions(),
            'accountOptions' => $this->accountOptions(),
            'categoryOptions' => $this->categoryOptions(),
        ]);
    }

    public function store(Request $request, MoneySyncService $sync): RedirectResponse
    {
        MoneyRecurring::query()->create($this->validated($request) + ['active' => true]);
        // Échéance déjà passée (ex. loyer du 1er saisi le 5) : ajoutée tout de suite.
        $sync->runRecurring();
        app(MoneyAlertService::class)->check();

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
        app(MoneyAlertService::class)->check();

        return redirect()->route('recurrings.index')->with('status', 'Enregistré.');
    }

    /** Abonnement repéré dans les relevés : ajouté aux Fixes (ses anciens prélèvements y sont rattachés). */
    public function adopt(Request $request, MoneySubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:200'],
            'sub_label' => ['required', 'string', 'max:160'],
        ], [], ['sub_label' => 'libellé']);
        $data['label'] = $data['sub_label'];
        $found = $subscriptions->find($data['key']);
        if (! $found) {
            return redirect()->route('recurrings.index')->withErrors(['key' => 'Cet abonnement n\'est plus proposé (déjà ajouté ou ignoré).']);
        }

        DB::transaction(function () use ($found, $data) {
            $recurring = MoneyRecurring::query()->create([
                'label' => $data['label'],
                'amount' => $found['amount'],
                'account_id' => $found['account']->id,
                'category_id' => $found['category_id'],
                'frequency' => $found['frequency'],
                'next_on' => $found['next_on']->toDateString(),
                'active' => true,
            ]);
            MoneyTransaction::query()->whereIn('id', $found['ids'])->update(['recurring_id' => $recurring->id]);
        });

        return redirect()->route('recurrings.index')->with('status', '« '.$data['label'].' » ajouté aux Fixes : prochain prélèvement prévu le '.$found['next_on']->format('d/m/Y').'.');
    }

    /** « Ce n'est pas un abonnement » : plus proposé. */
    public function dismiss(Request $request, MoneySubscriptionService $subscriptions): RedirectResponse
    {
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:200']])['key'];
        $subscriptions->dismiss($key);

        return redirect()->route('recurrings.index')->with('status', 'Compris, il ne sera plus proposé.');
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
            'type' => ['required', Rule::in(['expense', 'income', 'transfer'])],
            'account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')],
            'to_account_id' => ['nullable', 'required_if:type,transfer', 'integer', 'different:account_id', Rule::exists('money_accounts', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('money_categories', 'id')],
            'frequency' => ['required', Rule::in(array_keys(MoneyRecurring::FREQUENCIES))],
            'next_on' => ['required', 'date', 'after_or_equal:'.today()->subYear()->toDateString()],
        ], [
            'next_on.after_or_equal' => 'Choisissez une date de moins d\'un an.',
            'to_account_id.required_if' => 'Choisissez le compte qui reçoit l\'argent.',
            'to_account_id.different' => 'Choisissez deux comptes différents.',
        ], ['label' => 'libellé', 'next_on' => 'prochaine date']);
        $amount = $this->amount($request, 'amount');
        if (! empty($data['category_id']) && MoneyCategory::query()->whereKey($data['category_id'])->value('type') !== $data['type']) {
            throw ValidationException::withMessages(['category_id' => $data['type'] === 'expense' ? 'Choisissez une catégorie de dépense.' : 'Choisissez une catégorie de revenu.']);
        }
        if ($data['type'] === 'transfer') {
            $data['category_id'] = null;
        } else {
            $data['to_account_id'] = null;
        }
        $data['amount'] = $data['type'] === 'expense' ? -$amount : $amount;
        unset($data['type']);

        return $data;
    }
}
