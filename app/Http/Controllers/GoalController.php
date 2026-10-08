<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyGoal;
use App\Services\MoneyStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Objectifs : épargne (avec date), chiffre encaissé, gain, plafond de dépenses. */
class GoalController extends Controller
{
    use ReadsMoneyInput;

    public function index(MoneyStatsService $stats): View
    {
        $goals = MoneyGoal::query()->with('account')->orderByRaw('archived_at IS NOT NULL')->orderBy('id')->get();

        return view('goals.index', [
            'goals' => $goals->whereNull('archived_at')->map(fn (MoneyGoal $goal) => ['goal' => $goal] + $stats->goal($goal)),
            'archived' => $goals->whereNotNull('archived_at'),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MoneyGoal::query()->create($this->validated($request));

        return redirect()->route('goals.index')->with('status', 'Objectif ajouté. Bon courage !');
    }

    public function update(Request $request, MoneyGoal $goal): RedirectResponse
    {
        $data = $this->validated($request, $goal);
        $data['archived_at'] = $request->boolean('archived') ? ($goal->archived_at ?? now()) : null;
        $goal->update($data);

        return redirect()->route('goals.index')->with('status', 'Objectif enregistré.');
    }

    /** Épargne sans compte suivi : ajouter (ou retirer) ce qui a été mis de côté. */
    public function contribute(Request $request, MoneyGoal $goal, MoneyStatsService $stats): RedirectResponse
    {
        abort_unless($goal->isSaving() && ! $goal->account_id, 404);
        $amount = $this->amount($request, 'contribution', positive: false);
        $goal->update(['saved' => max(0, $goal->saved + $amount)]);

        $progress = $stats->goal($goal->fresh());
        if ($progress['percent'] >= 100 && ! $goal->achieved_at) {
            $goal->update(['achieved_at' => now()]);

            return redirect()->route('goals.index')->with('status', 'Objectif « '.$goal->name.' » atteint, bravo !');
        }

        return redirect()->route('goals.index')->with('status', 'Montant mis de côté enregistré.');
    }

    public function destroy(MoneyGoal $goal): RedirectResponse
    {
        $goal->delete();

        return redirect()->route('goals.index')->with('status', 'Objectif supprimé.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MoneyGoal $goal = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(array_keys(MoneyGoal::KINDS))],
            'period' => ['nullable', Rule::in(array_keys(MoneyGoal::PERIODS))],
            'scope' => ['nullable', Rule::in(array_keys(MoneyGoal::SCOPES))],
            'deadline' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
        ], [], ['name' => 'nom', 'deadline' => 'date']);
        $data['target'] = $this->amount($request, 'target');

        if ($data['kind'] === 'epargne') {
            $data['period'] = null;
            $data['scope'] = 'all';
            if (! $goal) {
                $data['saved'] = $this->amount($request, 'saved', required: false) ?? 0;
            }
        } else {
            $data['period'] ??= 'mois';
            $data['scope'] ??= $data['kind'] === 'encaisse' ? 'pro' : 'all';
            $data['deadline'] = null;
            $data['account_id'] = null;
        }

        return $data;
    }
}
