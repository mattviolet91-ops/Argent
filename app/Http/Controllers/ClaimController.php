<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyTransaction;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Notes de frais : une dépense pro payée avec un compte perso compte en pro
 * (pas dans les dépenses perso), et reste « à se faire rembourser » jusqu'au
 * virement du compte pro vers le compte perso (ni gagné, ni dépensé).
 */
class ClaimController extends Controller
{
    use ReadsMoneyInput;

    public function index(): View
    {
        $pending = MoneyTransaction::query()->where('claim', 'a_rembourser')->with(['account', 'category'])->withCount('attachments')->orderBy('occurred_on')->get();
        $settled = MoneyTransaction::query()->where('claim', 'rembourse')->with(['account', 'category'])->orderByDesc('claim_settled_on')->orderByDesc('id')->limit(60)->get();
        $accounts = $this->accountOptions();

        return view('claims.index', [
            'pending' => $pending,
            'settled' => $settled,
            'pendingTotal' => (int) -$pending->sum('amount'),
            'settledThisYear' => (int) -MoneyTransaction::query()->where('claim', 'rembourse')->whereYear('claim_settled_on', today()->year)->sum('amount'),
            'proAccounts' => $accounts->where('scope', 'pro')->values(),
            'persoAccounts' => $accounts->where('scope', 'perso')->values(),
            'oldest' => $pending->first()?->occurred_on,
        ]);
    }

    /** Notes de frais remboursées : virement du compte pro vers le compte perso (facultatif). */
    public function settle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'settled_on' => ['required', 'date'],
            'from_account' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
            'to_account' => ['nullable', 'integer', Rule::exists('money_accounts', 'id'), 'different:from_account'],
        ], ['ids.required' => 'Cochez les notes de frais remboursées.', 'to_account.different' => 'Choisissez deux comptes différents.'], ['settled_on' => 'date']);
        $claims = MoneyTransaction::query()->where('claim', 'a_rembourser')->whereIn('id', $data['ids'])->get();
        if ($claims->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Ces notes de frais sont déjà remboursées.']);
        }
        $total = (int) -$claims->sum('amount');
        $transfer = ! empty($data['from_account']) && ! empty($data['to_account']);

        DB::transaction(function () use ($claims, $data, $total, $transfer) {
            $key = null;
            if ($transfer) {
                $key = (string) Str::uuid();
                $label = 'Remboursement '.($claims->count() > 1 ? $claims->count().' notes de frais' : 'note de frais · '.$claims->first()->label);
                foreach ([[$data['from_account'], -$total], [$data['to_account'], $total]] as [$accountId, $amount]) {
                    MoneyTransaction::query()->create([
                        'account_id' => $accountId, 'occurred_on' => $data['settled_on'], 'amount' => $amount, 'kind' => 'transfer',
                        'label' => mb_substr($label, 0, 160), 'transfer_key' => $key, 'source' => 'manual',
                    ]);
                }
            }
            MoneyTransaction::query()->whereIn('id', $claims->pluck('id'))
                ->update(['claim' => 'rembourse', 'claim_settled_on' => $data['settled_on'], 'claim_key' => $key]);
        });

        return redirect()->route('claims.index')->with('status', Money::plain($total).' remboursés'.($transfer ? ' : le virement est noté sur vos comptes.' : '.'));
    }

    /** Remboursement noté par erreur : la note de frais redevient à rembourser. */
    public function reopen(MoneyTransaction $transaction): RedirectResponse
    {
        abort_unless($transaction->claim === 'rembourse', 404);
        $transaction->update(['claim' => 'a_rembourser', 'claim_settled_on' => null, 'claim_key' => null]);

        return redirect()->route('claims.index')->with('status', 'Remise dans « à rembourser » (le virement éventuel reste dans Mouvements).');
    }
}
