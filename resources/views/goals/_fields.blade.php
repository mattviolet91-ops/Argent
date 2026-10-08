{{-- Champs d'un objectif. $goal : MoneyGoal|null --}}
@php
    $prefix = $goal ? 'goal-'.$goal->id.'-' : 'new-goal-';
    $accountChoice = old('account_id', $goal ? ($goal->account_id ?? '') : 'new');
@endphp
<div class="form-grid cols-2" data-money-switch="kind">
    <x-field name="name" label="Nom" :value="$goal?->name" required maxlength="80" placeholder="ex. Nouvelle voiture, vacances, 8 000 € par mois" />
    <div class="field">
        <label for="{{ $prefix }}kind">Type</label>
        <select id="{{ $prefix }}kind" name="kind">
            @foreach (\App\Models\MoneyGoal::KINDS as $key => $label)
                <option value="{{ $key }}" @selected(old('kind', $goal?->kind ?? 'epargne') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <x-field name="target" label="Montant visé (€)" :value="$goal ? \App\Support\Money::format($goal->target, false) : ''" required inputmode="decimal" placeholder="ex. 15 000" />
    <div class="field" data-when="epargne">
        <label for="{{ $prefix }}deadline">Date d'échéance <span class="muted small">(pour quand ?)</span></label>
        <input id="{{ $prefix }}deadline" type="date" name="deadline" value="{{ old('deadline', $goal?->deadline?->toDateString()) }}">
    </div>
    <div class="field" data-when="epargne">
        <label for="{{ $prefix }}icon">Icône</label>
        <select id="{{ $prefix }}icon" name="icon">
            @foreach (\App\Models\MoneyGoal::ICONS as $key => [$label])
                <option value="{{ $key }}" @selected(old('icon', $goal?->icon ?? 'autre') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="field" data-when="epargne">
        <label for="{{ $prefix }}account">Où est l'argent ?</label>
        <select id="{{ $prefix }}account" name="account_id">
            @unless ($goal?->account_id)
                <option value="new" @selected($accountChoice === 'new')>Un compte séparé, rien que pour cet objectif (conseillé)</option>
            @endunless
            @foreach ($accountOptions as $account)
                <option value="{{ $account->id }}" @selected((string) $accountChoice === (string) $account->id)>Sur le compte existant « {{ $account->name }} »</option>
            @endforeach
            @unless ($goal?->account_id)
                <option value="" @selected($accountChoice === '')>Je note à la main ce que je mets de côté</option>
            @endunless
        </select>
    </div>
    @unless ($goal)
        <div class="field" data-when="epargne">
            <label for="{{ $prefix }}saved">Déjà mis de côté (€)</label>
            <input id="{{ $prefix }}saved" type="text" name="saved" inputmode="decimal" placeholder="0,00" value="{{ old('saved') }}">
        </div>
    @endunless
    <div class="field" data-when="encaisse gain depenses">
        <label for="{{ $prefix }}period">Période</label>
        <select id="{{ $prefix }}period" name="period">
            @foreach (\App\Models\MoneyGoal::PERIODS as $key => $label)
                <option value="{{ $key }}" @selected(old('period', $goal?->period ?? 'mois') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="field" data-when="encaisse gain depenses">
        <label for="{{ $prefix }}scope">Sur</label>
        <select id="{{ $prefix }}scope" name="scope">
            @foreach (\App\Models\MoneyGoal::SCOPES as $key => $label)
                <option value="{{ $key }}" @selected(old('scope', $goal?->scope ?? 'all') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
