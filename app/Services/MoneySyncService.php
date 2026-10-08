<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyAttachment;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lien avec l'app de devis : chaque paiement reçu devient une entrée et
 * chaque frais une sortie sur le compte pro choisi. Relancer la mise à jour ne crée
 * jamais de doublon ; un paiement supprimé (ou une facture annulée) disparaît.
 * La catégorie et le libellé changés à la main sont gardés.
 */
class MoneySyncService
{
    public function __construct(private readonly Settings $settings, private readonly DevisClient $devis) {}

    /** Compte qui reçoit les paiements et les frais (null : lien désactivé). */
    public function account(): ?MoneyAccount
    {
        $id = (int) $this->settings->get('argent.sync_account_id', 0);

        return $id ? MoneyAccount::query()->find($id) : null;
    }

    /** Compte facultatif pour les paiements en espèces. */
    public function cashAccount(): ?MoneyAccount
    {
        $id = (int) $this->settings->get('argent.cash_account_id', 0);

        return $id ? MoneyAccount::query()->find($id) : null;
    }

    /**
     * Mise à jour depuis l'app de devis. null : lien pas réglé (ou pas de compte choisi).
     *
     * @return array{added: int, updated: int, removed: int}|null
     *
     * @throws \RuntimeException app de devis injoignable ou clé refusée (rien n'est modifié)
     */
    public function run(): ?array
    {
        $account = $this->account();
        if (! $account || ! $this->devis->isConfigured()) {
            return null;
        }
        $data = $this->devis->fetch();
        $cash = $this->cashAccount();
        $result = ['added' => 0, 'updated' => 0, 'removed' => 0];
        $seen = [];

        DB::transaction(function () use ($data, $account, $cash, &$result, &$seen) {
            $income = MoneyCategory::system('devis_payment');
            foreach ($data['payments'] as $payment) {
                $ref = 'payment:'.(int) $payment['id'];
                $seen[$ref] = true;
                $this->upsert($ref, [
                    'account_id' => ($cash && ($payment['method'] ?? '') === 'especes') ? $cash->id : $account->id,
                    'occurred_on' => (string) $payment['date'],
                    'amount' => (int) $payment['amount'],
                ], [
                    'category_id' => $income?->id,
                    'label' => mb_substr('Paiement '.($payment['client'] ?: 'client').(! empty($payment['invoice']) ? ' · facture '.$payment['invoice'] : ''), 0, 160),
                ], $result);
            }

            $categories = MoneyCategory::query()->whereIn('system_key', MoneyCategory::EXPENSE_KEYS)->pluck('id', 'system_key');
            foreach ($data['expenses'] as $expense) {
                $ref = 'expense:'.(int) $expense['id'];
                $seen[$ref] = true;
                $key = MoneyCategory::EXPENSE_KEYS[$expense['category'] ?? ''] ?? 'devis_autre';
                $this->upsert($ref, [
                    'account_id' => $account->id,
                    'occurred_on' => (string) $expense['date'],
                    'amount' => -(int) $expense['amount'],
                ], [
                    'category_id' => $categories[$key] ?? null,
                    'label' => mb_substr((string) $expense['label'].(! empty($expense['supplier']) ? ' · '.$expense['supplier'] : ''), 0, 160),
                ], $result);
            }

            $gone = MoneyTransaction::query()->where('source', 'devis')->pluck('source_ref', 'id')
                ->reject(fn ($ref) => isset($seen[$ref]))->keys();
            foreach ($gone->chunk(500) as $ids) {
                MoneyAttachment::purgeFor($ids);
                $result['removed'] += MoneyTransaction::query()->whereIn('id', $ids)->delete();
            }
        });

        $this->settings->set([
            'argent.last_sync_at' => now()->toIso8601String(),
            'argent.last_sync' => $result,
            'devis.summary' => array_map('intval', array_intersect_key((array) $data['summary'], array_flip(['to_collect', 'overdue_invoices', 'pending_quotes', 'pending_amount']))),
        ]);

        return $result;
    }

    /**
     * Dépenses et revenus fixes arrivés à échéance : ajoutés aux mouvements (rattrape les jours manqués).
     */
    public function runRecurring(?Carbon $today = null): int
    {
        $today ??= today();
        $created = 0;
        foreach (MoneyRecurring::query()->where('active', true)->whereDate('next_on', '<=', $today)->get() as $recurring) {
            $date = $recurring->next_on->copy();
            for ($i = 0; $i < 60 && $date->lte($today); $i++) {
                if ($recurring->isTransfer()) {
                    $key = (string) Str::uuid();
                    foreach ([[$recurring->account_id, -abs($recurring->amount)], [$recurring->to_account_id, abs($recurring->amount)]] as [$accountId, $amount]) {
                        MoneyTransaction::query()->create([
                            'account_id' => $accountId, 'occurred_on' => $date->toDateString(), 'amount' => $amount, 'kind' => 'transfer',
                            'label' => $recurring->label, 'transfer_key' => $key, 'source' => 'recurring', 'recurring_id' => $recurring->id,
                        ]);
                    }
                } else {
                    MoneyTransaction::query()->create([
                        'account_id' => $recurring->account_id,
                        'occurred_on' => $date->toDateString(),
                        'amount' => $recurring->amount,
                        'kind' => MoneyTransaction::kindFor($recurring->amount),
                        'category_id' => $recurring->category_id,
                        'label' => $recurring->label,
                        'source' => 'recurring',
                        'recurring_id' => $recurring->id,
                    ]);
                }
                $created++;
                $date = $recurring->nextAfter($date);
            }
            $recurring->update(['next_on' => $date->toDateString()]);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $values  toujours à jour (compte, date, montant)
     * @param  array<string, mixed>  $initial  seulement à la création (catégorie, libellé)
     * @param  array{added: int, updated: int, removed: int}  $result
     */
    private function upsert(string $ref, array $values, array $initial, array &$result): void
    {
        $values['kind'] = MoneyTransaction::kindFor((int) $values['amount']);
        $transaction = MoneyTransaction::query()->where('source_ref', $ref)->first();

        if (! $transaction) {
            MoneyTransaction::query()->create($values + $initial + ['source' => 'devis', 'source_ref' => $ref]);
            $result['added']++;

            return;
        }

        $transaction->fill($values);
        if ($transaction->isDirty()) {
            $transaction->save();
            $result['updated']++;
        }
    }
}
