<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyAccount;
use App\Services\MoneyStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Comptes (courant, livret, espèces…) perso et pro, avec leur solde. */
class AccountController extends Controller
{
    use ReadsMoneyInput;

    public function index(MoneyStatsService $stats): View
    {
        $accounts = MoneyAccount::query()->ordered()->get()
            ->each(fn (MoneyAccount $account) => $account->setAttribute('current_balance', $account->balance()));
        $active = $accounts->whereNull('archived_at');

        return view('accounts.index', [
            'accounts' => $active,
            'archived' => $accounts->whereNotNull('archived_at'),
            'totals' => [
                'all' => (int) $active->sum('current_balance'),
                'perso' => (int) $active->where('scope', 'perso')->sum('current_balance'),
                'pro' => (int) $active->where('scope', 'pro')->sum('current_balance'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['position'] = (int) MoneyAccount::query()->max('position') + 1;
        $account = MoneyAccount::query()->create($data);

        return redirect()->route('accounts.show', $account)->with('status', 'Compte ajouté.');
    }

    /** Un compte : solde, évolution sur 12 mois, derniers mouvements. */
    public function show(MoneyAccount $account): View
    {
        $points = [];
        $end = today();
        for ($i = 11; $i >= 0; $i--) {
            $day = $i === 0 ? $end->copy() : $end->copy()->subMonthsNoOverflow($i)->endOfMonth()->startOfDay();
            $points[] = ['day' => $day, 'balance' => $day->lt($account->opening_on) ? null : $account->balance($day)];
        }

        return view('accounts.show', [
            'account' => $account,
            'balance' => $account->balance(),
            'points' => $points,
            'latest' => $account->transactions()->with('category')->latest('occurred_on')->latest('id')->limit(15)->get(),
            'count' => $account->transactions()->count(),
        ]);
    }

    public function update(Request $request, MoneyAccount $account): RedirectResponse
    {
        $data = $this->validated($request);
        $data['archived_at'] = $request->boolean('archived') ? ($account->archived_at ?? now()) : null;
        $account->update($data);

        return redirect()->route('accounts.show', $account)->with('status', 'Compte enregistré.');
    }

    /** Supprimer un compte vide ; un compte avec des mouvements est seulement archivé. */
    public function destroy(MoneyAccount $account): RedirectResponse
    {
        if ($account->transactions()->exists()) {
            $account->update(['archived_at' => $account->archived_at ?? now()]);

            return redirect()->route('accounts.index')->with('status', 'Le compte a des mouvements : il est archivé (masqué) au lieu d\'être supprimé.');
        }
        $account->delete();

        return redirect()->route('accounts.index')->with('status', 'Compte supprimé.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(array_keys(MoneyAccount::KINDS))],
            'scope' => ['required', Rule::in(array_keys(MoneyAccount::SCOPES))],
            'opening_on' => ['required', 'date'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [], ['name' => 'nom', 'opening_on' => 'date du solde']);
        $data['opening_balance'] = $this->amount($request, 'opening_balance', positive: false, required: false) ?? 0;

        return $data;
    }
}
