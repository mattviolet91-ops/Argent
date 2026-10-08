{{-- Champs d'un crédit. $credit : crédit ou null. --}}
@php $f = fn (?int $cents) => $cents ? \App\Support\Money::format($cents, false) : ''; @endphp
<div class="form-grid cols-2">
    <x-field name="name" label="Nom" :value="$credit?->name" required maxlength="80" placeholder="ex. Crédit voiture, prêt maison" />
    <x-field name="principal" label="Montant emprunté (€)" inputmode="decimal" :value="$f($credit?->principal)" required placeholder="ex. 18 000" />
    <x-field name="rate" label="Taux annuel (%)" inputmode="decimal" :value="$credit ? str_replace('.', ',', (string) ($credit->rate / 100)) : ''" placeholder="ex. 3,45 (vide = 0 %)" />
    <x-field name="first_due_on" label="1re mensualité le" type="date" :value="$credit?->first_due_on?->toDateString()" required />
    <x-field name="months" label="Durée (mois)" type="number" min="1" max="480" :value="$credit?->months" placeholder="ex. 60" hint="Ou laissez vide et donnez la mensualité." />
    <x-field name="monthly" label="Mensualité hors assurance (€)" inputmode="decimal" :value="$f($credit?->monthly)" placeholder="calculée si vide" />
    <x-field name="insurance" label="Assurance par mois (€)" inputmode="decimal" :value="$f($credit?->insurance)" placeholder="facultatif" />
    <div class="field @error('credit_account') has-error @enderror">
        <label for="credit_account{{ $credit?->id }}">Payé avec le compte</label>
        <select id="credit_account{{ $credit?->id }}" name="credit_account">
            <option value="">—</option>
            @foreach ($accountOptions as $account)
                <option value="{{ $account->id }}" @selected((string) old('credit_account', $credit?->account_id) === (string) $account->id)>{{ $account->name }}</option>
            @endforeach
        </select>
        @error('credit_account')<span class="error">{{ $message }}</span>@enderror
    </div>
    <x-field name="credit_notes" label="Note" :value="$credit?->notes" maxlength="500" class="span-2" placeholder="banque, n° de contrat…" />
</div>
