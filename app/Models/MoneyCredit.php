<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Crédit (voiture, maison, travaux…) : capital, taux, durée, date de la 1re
 * mensualité. Tout le reste se calcule : capital restant, intérêts restants,
 * date de fin, tableau d'amortissement.
 */
class MoneyCredit extends Model
{
    protected $fillable = ['name', 'principal', 'rate', 'months', 'monthly', 'insurance', 'first_due_on', 'account_id', 'recurring_id', 'notes', 'archived_at'];

    protected function casts(): array
    {
        return [
            'principal' => 'integer', 'rate' => 'integer', 'months' => 'integer', 'monthly' => 'integer',
            'insurance' => 'integer', 'first_due_on' => 'date', 'archived_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'account_id');
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(MoneyRecurring::class, 'recurring_id');
    }

    /** Taux mensuel (3,45 % par an → 0,002875). */
    public static function monthlyRate(int $rate): float
    {
        return $rate / 10000 / 12;
    }

    /** Mensualité hors assurance pour un capital, un taux (centièmes de %) et une durée. */
    public static function payment(int $principal, int $rate, int $months): int
    {
        $r = self::monthlyRate($rate);
        if ($months <= 0) {
            return $principal;
        }

        return (int) round($r === 0.0 ? $principal / $months : $principal * $r / (1 - (1 + $r) ** -$months));
    }

    /** Durée (mois) pour rembourser un capital avec une mensualité donnée ; null si la mensualité ne couvre pas les intérêts. */
    public static function duration(int $principal, int $rate, int $monthly): ?int
    {
        $r = self::monthlyRate($rate);
        if ($monthly <= 0) {
            return null;
        }
        if ($r === 0.0) {
            return (int) ceil($principal / $monthly);
        }
        if ($monthly <= $principal * $r) {
            return null;
        }

        return (int) ceil(-log(1 - $r * $principal / $monthly) / log(1 + $r));
    }

    /** Date de l'échéance n° $n (1 = la première). */
    public function dueDate(int $n): Carbon
    {
        return $this->first_due_on->copy()->addMonthsNoOverflow($n - 1);
    }

    /** Mensualités déjà passées à cette date. */
    public function paidCount(?Carbon $at = null): int
    {
        $at ??= today();
        if ($at->lt($this->first_due_on)) {
            return 0;
        }
        $count = (int) $this->first_due_on->diffInMonths($at) + 1;
        if ($this->dueDate($count)->gt($at)) {
            $count--;
        }

        return max(0, min($this->months, $count));
    }

    /** Capital restant dû après $paid mensualités. */
    public function remainingAfter(int $paid): int
    {
        $r = self::monthlyRate($this->rate);
        $balance = (float) $this->principal;
        for ($i = 0; $i < min($paid, $this->months); $i++) {
            $interest = $balance * $r;
            $balance -= min($balance, $this->monthly - $interest);
        }

        return $paid >= $this->months ? 0 : (int) round(max(0, $balance));
    }

    public function remaining(?Carbon $at = null): int
    {
        return $this->remainingAfter($this->paidCount($at));
    }

    public function endDate(): Carbon
    {
        return $this->dueDate($this->months);
    }

    /** Coût total des intérêts sur toute la durée (hors assurance). */
    public function totalInterest(): int
    {
        return (int) array_sum(array_column($this->schedule(), 'interest'));
    }

    /** Intérêts encore à payer. */
    public function remainingInterest(?Carbon $at = null): int
    {
        $paid = $this->paidCount($at);

        return (int) array_sum(array_column(array_slice($this->schedule(), $paid), 'interest'));
    }

    /**
     * Tableau d'amortissement : chaque mensualité, sa part d'intérêts et de capital.
     *
     * @return list<array{n: int, date: Carbon, payment: int, interest: int, capital: int, remaining: int}>
     */
    public function schedule(): array
    {
        $r = self::monthlyRate($this->rate);
        $balance = (float) $this->principal;
        $rows = [];
        for ($n = 1; $n <= $this->months; $n++) {
            $interest = $balance * $r;
            $capital = $n === $this->months ? $balance : min($balance, $this->monthly - $interest);
            $balance -= $capital;
            $rows[] = [
                'n' => $n, 'date' => $this->dueDate($n),
                'payment' => (int) round($interest + $capital), 'interest' => (int) round($interest),
                'capital' => (int) round($capital), 'remaining' => (int) round(max(0, $balance)),
            ];
        }

        return $rows;
    }

    /** Part déjà remboursée du capital, en %. */
    public function percentRepaid(?Carbon $at = null): int
    {
        return $this->principal > 0 ? (int) round(($this->principal - $this->remaining($at)) * 100 / $this->principal) : 100;
    }
}
