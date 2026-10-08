{{-- Champs d'une ligne : prêt, avance, emprunt ou remboursement. $type : type coché par défaut. --}}
@php $type = old('loan_type', $type ?? 'pret'); @endphp
<div class="loan-types" role="radiogroup" aria-label="Type">
    @foreach (\App\Models\MoneyLoanEntry::TYPES as $key => [$label])
        <label class="loan-type"><input type="radio" name="loan_type" value="{{ $key }}" @checked($type === $key)> <span>{{ $label }}</span></label>
    @endforeach
</div>
<div class="form-grid cols-2" style="margin-top:.75rem">
    <div class="field @error('loan_amount') has-error @enderror">
        <label for="loan_amount{{ $suffix ?? '' }}">Montant (€)</label>
        <input id="loan_amount{{ $suffix ?? '' }}" class="amount-input" type="text" name="loan_amount" inputmode="decimal" placeholder="0,00" value="{{ old('loan_amount', $amount ?? '') }}" required>
        @error('loan_amount')<span class="error">{{ $message }}</span>@enderror
    </div>
    <div class="field @error('loan_on') has-error @enderror">
        <label for="loan_on{{ $suffix ?? '' }}">Date</label>
        <input id="loan_on{{ $suffix ?? '' }}" type="date" name="loan_on" value="{{ old('loan_on', today()->toDateString()) }}" required>
        @error('loan_on')<span class="error">{{ $message }}</span>@enderror
    </div>
    <div class="field">
        <label for="loan_account{{ $suffix ?? '' }}">Noter aussi sur le compte <span class="muted small">(facultatif)</span></label>
        <select id="loan_account{{ $suffix ?? '' }}" name="loan_account">
            <option value="">— Non —</option>
            @foreach ($accountOptions as $account)
                <option value="{{ $account->id }}" @selected((string) old('loan_account') === (string) $account->id)>{{ $account->name }}</option>
            @endforeach
        </select>
        <span class="hint">Ni gagné, ni dépensé. Laissez « Non » si le virement arrive déjà par un relevé importé.</span>
    </div>
    <div class="field @error('note') has-error @enderror">
        <label for="note{{ $suffix ?? '' }}">Note</label>
        <input id="note{{ $suffix ?? '' }}" type="text" name="note" value="{{ old('note') }}" maxlength="160" placeholder="ex. matériel avancé, resto, chantier Dupont">
        @error('note')<span class="error">{{ $message }}</span>@enderror
    </div>
</div>
