<?php

namespace App\Http\Controllers\Concerns;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Saisies communes de l'app Argent : montants en euros, vue perso / pro, listes de choix. */
trait ReadsMoneyInput
{
    /** « 1 234,56 » → centimes ; erreur de saisie sinon. */
    private function amount(Request $request, string $field, bool $positive = true, bool $required = true): ?int
    {
        $value = $request->input($field);
        if (($value === null || trim((string) $value) === '') && ! $required) {
            return null;
        }
        // Le signe « − » affiché par l'application (Money::format) est accepté.
        $cents = Money::parse(is_scalar($value) ? str_replace(['−', '–'], '-', (string) $value) : null);
        if ($cents === null || ($positive && $cents <= 0)) {
            throw ValidationException::withMessages([$field => $positive ? 'Indiquez un montant supérieur à 0 (ex. 12,50).' : 'Montant invalide (ex. 1 250,00 ou -80).']);
        }

        return $cents;
    }

    /** Vue choisie : tout, perso ou pro (gardée pendant la session). */
    private function scope(Request $request): string
    {
        $scope = (string) $request->query('vue', $request->session()->get('argent.scope', 'all'));
        $scope = in_array($scope, ['all', 'perso', 'pro'], true) ? $scope : 'all';
        $request->session()->put('argent.scope', $scope);

        return $scope;
    }

    /** @return Collection<int, MoneyAccount> */
    private function accountOptions(): Collection
    {
        return MoneyAccount::query()->active()->ordered()->get();
    }

    /** @return Collection<int, MoneyCategory> */
    private function categoryOptions(): Collection
    {
        return MoneyCategory::query()->active()->ordered()->get();
    }
}
