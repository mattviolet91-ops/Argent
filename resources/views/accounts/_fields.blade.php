{{-- Champs d'un compte. $account : MoneyAccount|null --}}
<div class="form-grid cols-2">
    <x-field name="name" label="Nom" :value="$account?->name" required maxlength="80" placeholder="ex. Compte Crédit Agricole, Livret A" />
    <div class="field @error('kind') has-error @enderror">
        <label for="kind">Type</label>
        <select id="kind" name="kind">
            @foreach (\App\Models\MoneyAccount::GROUPS as $group => $groupLabel)
                <optgroup label="{{ $groupLabel }}">
                    @foreach (array_filter(\App\Models\MoneyAccount::TYPES, fn ($type) => $type[1] === $group) as $key => [$typeLabel])
                        <option value="{{ $key }}" @selected(old('kind', $account?->kind ?? 'courant') === $key)>{{ $typeLabel }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error('kind')<span class="error">{{ $message }}</span>@enderror
    </div>
    <x-select name="scope" label="Perso ou pro" :options="\App\Models\MoneyAccount::SCOPES" :value="$account?->scope ?? 'perso'" :placeholder="false" />
    <div class="field @error('color') has-error @enderror">
        <label for="color">Couleur</label>
        <input id="color" type="color" name="color" value="{{ old('color', $account?->color ?? '#2E7DBA') }}">
    </div>
    <x-field name="opening_balance" label="Solde (€)" :value="$account ? \App\Support\Money::format($account->opening_balance, false) : ''" inputmode="decimal" placeholder="0,00" hint="Ce qu'il y avait sur le compte à la date ci-contre (négatif possible). Pour un crédit : ce qu'il reste à rembourser. Les remboursements se notent en « Virement » vers ce compte." />
    <x-field name="opening_on" label="À la date du" type="date" :value="($account?->opening_on ?? today())->toDateString()" required hint="Les mouvements d'avant ne changent pas le solde (mais comptent dans les bilans)." />
    <x-field name="alert_below" label="Me prévenir si le solde passe sous (€)" :value="$account?->alert_below !== null ? \App\Support\Money::format($account->alert_below, false) : ''" inputmode="decimal" placeholder="pas d'alerte" hint="Une notification quand le solde passe en dessous, et une alerte sur le résumé." />
</div>
