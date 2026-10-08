{{-- Champs d'une dépense / d'un revenu fixe. $recurring : MoneyRecurring|null --}}
@php
    $type = old('type', $recurring && $recurring->amount > 0 ? 'income' : 'expense');
    $prefix = $recurring ? 'rec-'.$recurring->id.'-' : 'new-rec-';
@endphp
<div data-money-switch="type">
    <div class="type-switch two" role="radiogroup" aria-label="Type">
        <label><input type="radio" name="type" value="expense" @checked($type === 'expense')> Dépense</label>
        <label><input type="radio" name="type" value="income" @checked($type === 'income')> Revenu</label>
    </div>
    <div class="form-grid cols-2">
        <x-field name="label" label="Libellé" :value="$recurring?->label" required maxlength="160" placeholder="ex. Loyer, Netflix, crédit camion, salaire" />
        <x-field name="amount" label="Montant (€)" :value="$recurring ? \App\Support\Money::format(abs($recurring->amount), false) : ''" required inputmode="decimal" placeholder="0,00" />
        <div class="field">
            <label for="{{ $prefix }}frequency">Revient</label>
            <select id="{{ $prefix }}frequency" name="frequency">
                @foreach (\App\Models\MoneyRecurring::FREQUENCIES as $key => $label)
                    <option value="{{ $key }}" @selected(old('frequency', $recurring?->frequency ?? 'mensuel') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <x-field name="next_on" label="Prochaine date" type="date" :value="($recurring?->next_on ?? today())->toDateString()" required />
        <div class="field">
            <label for="{{ $prefix }}account">Compte</label>
            <select id="{{ $prefix }}account" name="account_id">
                @foreach ($accountOptions as $account)
                    <option value="{{ $account->id }}" @selected((string) old('account_id', $recurring?->account_id) === (string) $account->id)>{{ $account->name }} ({{ $account->scopeLabel() }})</option>
                @endforeach
            </select>
        </div>
        <div class="field @error('category_id') has-error @enderror">
            <label for="{{ $prefix }}category">Catégorie</label>
            <select id="{{ $prefix }}category" name="category_id" data-filter-options>
                <option value="">— Sans catégorie —</option>
                @foreach ($categoryOptions as $category)
                    <option value="{{ $category->id }}" data-when="{{ $category->type }}" @selected((string) old('category_id', $recurring?->category_id) === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            @error('category_id')<span class="error">{{ $message }}</span>@enderror
        </div>
    </div>
</div>
