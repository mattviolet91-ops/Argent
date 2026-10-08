<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyAccount;
use App\Models\MoneyGoal;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use App\Services\MoneyStatsService;
use App\Services\MoneySyncService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Objectifs. Épargne = un projet (« Nouvelle voiture ») avec son compte séparé :
 * dépôts et retraits datés (une date future = dépôt prévu), versements automatiques,
 * date d'échéance, rythme nécessaire et date estimée. Aussi : chiffre encaissé,
 * gain, plafond de dépenses.
 */
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
        $data = $this->validated($request);
        $goal = DB::transaction(function () use ($data, $request) {
            // « Nouveau compte séparé » : un compte rien que pour cet objectif.
            if ($data['kind'] === 'epargne' && $request->input('account_id') === 'new') {
                $account = MoneyAccount::query()->create([
                    'name' => mb_substr('Objectif · '.$data['name'], 0, 80),
                    'kind' => 'objectif',
                    'scope' => 'perso',
                    'opening_balance' => $data['saved'] ?? 0,
                    'opening_on' => today(),
                    'color' => '#0EA5E9',
                    'position' => (int) MoneyAccount::query()->max('position') + 1,
                ]);
                $data['account_id'] = $account->id;
                $data['saved'] = 0;
            }

            return MoneyGoal::query()->create($data);
        });

        return $goal->isSaving()
            ? redirect()->route('goals.show', $goal)->with('status', 'Objectif créé. Faites votre premier dépôt !')
            : redirect()->route('goals.index')->with('status', 'Objectif ajouté. Bon courage !');
    }

    /** Un objectif d'épargne : avancement, échéance, dépôts, versements automatiques. */
    public function show(MoneyGoal $goal, MoneyStatsService $stats): View
    {
        $goal->load('account');

        return view('goals.show', [
            'goal' => $goal,
            'progress' => $stats->goal($goal),
            'movements' => $goal->account
                ? $goal->account->transactions()->latest('occurred_on')->latest('id')->limit(50)->get()
                : collect(),
            'autos' => $goal->account_id
                ? MoneyRecurring::query()->where('to_account_id', $goal->account_id)->with('account')->orderByDesc('active')->get()
                : collect(),
            'sourceAccounts' => $this->accountOptions()->where('id', '!=', $goal->account_id)->values(),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function update(Request $request, MoneyGoal $goal): RedirectResponse
    {
        $data = $this->validated($request, $goal);
        $data['archived_at'] = $request->boolean('archived') ? ($goal->archived_at ?? now()) : null;
        $goal->update($data);
        app(MoneyAlertService::class)->check();

        return redirect()->route($goal->isSaving() ? 'goals.show' : 'goals.index', $goal->isSaving() ? $goal : [])->with('status', 'Objectif enregistré.');
    }

    /** Dépôt : virement d'un compte vers le compte de l'objectif (date future = prévu). */
    public function deposit(Request $request, MoneyGoal $goal): RedirectResponse
    {
        return $this->move($request, $goal, true);
    }

    /** Retrait : virement du compte de l'objectif vers un autre compte. */
    public function withdraw(Request $request, MoneyGoal $goal): RedirectResponse
    {
        return $this->move($request, $goal, false);
    }

    /** Versement automatique (chaque mois, chaque semaine…) vers le compte de l'objectif. */
    public function automatic(Request $request, MoneyGoal $goal, MoneySyncService $sync): RedirectResponse
    {
        abort_unless($goal->isSaving() && $goal->account_id, 404);
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id'), Rule::notIn([$goal->account_id])],
            'frequency' => ['required', Rule::in(array_keys(MoneyRecurring::FREQUENCIES))],
            'next_on' => ['required', 'date', 'after_or_equal:'.today()->toDateString()],
        ], ['next_on.after_or_equal' => 'Choisissez aujourd\'hui ou une date à venir.'], ['from_account_id' => 'compte', 'next_on' => 'premier versement']);
        $amount = $this->amount($request, 'auto_amount');

        MoneyRecurring::query()->create([
            'label' => mb_substr('Versement · '.$goal->name, 0, 160),
            'amount' => $amount,
            'account_id' => $data['from_account_id'],
            'to_account_id' => $goal->account_id,
            'frequency' => $data['frequency'],
            'next_on' => $data['next_on'],
            'active' => true,
        ]);
        $sync->runRecurring();
        app(MoneyAlertService::class)->check();

        return redirect()->route('goals.show', $goal)->with('status', 'Versement automatique programmé.');
    }

    /** Objectif suivi à la main : il passe sur un compte séparé (ce qui est déjà mis de côté y est reporté). */
    public function useAccount(MoneyGoal $goal): RedirectResponse
    {
        abort_unless($goal->isSaving() && ! $goal->account_id, 404);
        DB::transaction(function () use ($goal) {
            $account = MoneyAccount::query()->create([
                'name' => mb_substr('Objectif · '.$goal->name, 0, 80), 'kind' => 'objectif', 'scope' => 'perso',
                'opening_balance' => $goal->saved, 'opening_on' => today(), 'color' => '#0EA5E9',
                'position' => (int) MoneyAccount::query()->max('position') + 1,
            ]);
            $goal->update(['account_id' => $account->id, 'saved' => 0]);
        });

        return redirect()->route('goals.show', $goal)->with('status', 'L\'objectif a maintenant son compte séparé.');
    }

    /** Épargne sans compte suivi : ajouter (ou retirer) ce qui a été mis de côté. */
    public function contribute(Request $request, MoneyGoal $goal): RedirectResponse
    {
        abort_unless($goal->isSaving() && ! $goal->account_id, 404);
        $amount = $this->amount($request, 'contribution', positive: false);
        $goal->update(['saved' => max(0, $goal->saved + $amount)]);
        app(MoneyAlertService::class)->check();

        return redirect()->route('goals.show', $goal)->with('status', $goal->fresh()->achieved_at ? 'Objectif « '.$goal->name.' » atteint, bravo !' : 'Montant mis de côté enregistré.');
    }

    public function destroy(MoneyGoal $goal): RedirectResponse
    {
        $goal->delete();

        return redirect()->route('goals.index')->with('status', 'Objectif supprimé.'.($goal->account_id ? ' Son compte et son argent restent dans Comptes.' : ''));
    }

    private function move(Request $request, MoneyGoal $goal, bool $deposit): RedirectResponse
    {
        abort_unless($goal->isSaving() && $goal->account_id, 404);
        $data = $request->validate([
            'other_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id'), Rule::notIn([$goal->account_id])],
            'occurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['other_account_id' => 'compte', 'occurred_on' => 'date']);
        $amount = $this->amount($request, 'move_amount');
        if (! $deposit && $amount > $goal->account->balance()) {
            throw ValidationException::withMessages(['move_amount' => 'Il n\'y a que '.Money::format($goal->account->balance()).' sur cet objectif.']);
        }

        $key = (string) Str::uuid();
        $label = ($deposit ? 'Dépôt · ' : 'Retrait · ').$goal->name;
        DB::transaction(function () use ($data, $goal, $deposit, $amount, $key, $label) {
            foreach ([[$data['other_account_id'], $deposit ? -$amount : $amount], [$goal->account_id, $deposit ? $amount : -$amount]] as [$accountId, $value]) {
                MoneyTransaction::query()->create([
                    'account_id' => $accountId, 'occurred_on' => $data['occurred_on'], 'amount' => $value, 'kind' => 'transfer',
                    'label' => mb_substr($label, 0, 160), 'notes' => $data['notes'] ?? null, 'transfer_key' => $key, 'source' => 'manual',
                ]);
            }
        });
        app(MoneyAlertService::class)->check();

        $future = Carbon::parse($data['occurred_on'])->isFuture();
        $message = $deposit
            ? ($future ? 'Dépôt prévu le '.Carbon::parse($data['occurred_on'])->format('d/m/Y').'.' : 'Dépôt enregistré.')
            : 'Retrait enregistré.';
        if ($goal->fresh()->achieved_at && $deposit) {
            $message = 'Objectif « '.$goal->name.' » atteint, bravo !';
        }

        return redirect()->route('goals.show', $goal)->with('status', $message);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MoneyGoal $goal = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'icon' => ['nullable', Rule::in(array_keys(MoneyGoal::ICONS))],
            'kind' => ['required', Rule::in(array_keys(MoneyGoal::KINDS))],
            'period' => ['nullable', Rule::in(array_keys(MoneyGoal::PERIODS))],
            'scope' => ['nullable', Rule::in(array_keys(MoneyGoal::SCOPES))],
            'deadline' => ['nullable', 'date'],
            'account_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'new' && ! MoneyAccount::query()->whereKey((int) $value)->exists()) {
                    $fail('Compte introuvable.');
                }
            }],
        ], [], ['name' => 'nom', 'deadline' => 'date d\'échéance']);
        $data['target'] = $this->amount($request, 'target');
        $data['account_id'] = ($data['account_id'] ?? null) && $data['account_id'] !== 'new' ? (int) $data['account_id'] : null;

        if ($data['kind'] === 'epargne') {
            $data['period'] = null;
            $data['scope'] = 'all';
            $data['icon'] ??= 'autre';
            if (! $goal) {
                $data['saved'] = $this->amount($request, 'saved', required: false) ?? 0;
            } elseif ($goal->account_id && ! $request->has('account_id')) {
                $data['account_id'] = $goal->account_id;
            }
        } else {
            $data['period'] ??= 'mois';
            $data['scope'] ??= $data['kind'] === 'encaisse' ? 'pro' : 'all';
            $data['deadline'] = null;
            $data['account_id'] = null;
            $data['icon'] = null;
        }

        return $data;
    }
}
