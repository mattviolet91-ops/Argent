@extends('layouts.app', ['title' => 'Mouvement'])

@php
    $fromQuotes = $transaction->source === 'devis';
    $type = $transaction->amount >= 0 ? 'income' : 'expense';
@endphp

@section('content')
    @include('_nav')

    <div class="card">
        <div class="card-head">
            <h2>{{ $transaction->isTransfer() ? 'Virement' : ($type === 'income' ? 'Revenu' : 'Dépense') }}</h2>
            <span class="badge">{{ \App\Models\MoneyTransaction::SOURCES[$transaction->source] ?? '' }}</span>
        </div>
        @if ($fromQuotes)
            <div class="alert alert-info">Vient de l'app de devis (paiement ou frais) : le montant, la date et le compte se changent là-bas. Ici, vous pouvez changer la catégorie, le libellé et la note.</div>
        @endif
        @if ($transaction->isTransfer() && $peer)
            <p class="muted small">{{ $transaction->amount < 0 ? 'Vers' : 'Depuis' }} « {{ $peer->account?->name }} ». Les deux côtés du virement sont modifiés ensemble.</p>
        @endif

        <form method="POST" action="{{ route('transactions.update', $transaction) }}" data-money-switch="type">
            @csrf
            @method('PUT')
            @unless ($fromQuotes || $transaction->isTransfer())
                <div class="type-switch two" role="radiogroup" aria-label="Type">
                    <label><input type="radio" name="type" value="expense" @checked(old('type', $type) === 'expense')> Dépense</label>
                    <label><input type="radio" name="type" value="income" @checked(old('type', $type) === 'income')> Revenu</label>
                </div>
            @else
                <input type="hidden" name="type" value="{{ $type }}">
            @endunless
            <div class="form-grid cols-2">
                @unless ($fromQuotes)
                    <div class="field @error('amount') has-error @enderror">
                        <label for="amount">Montant (€)</label>
                        <input id="amount" class="amount-input" type="text" name="amount" value="{{ old('amount', \App\Support\Money::format(abs($transaction->amount), false)) }}" inputmode="decimal" required>
                        @error('amount')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <x-field name="occurred_on" label="Date" type="date" :value="$transaction->occurred_on->toDateString()" required />
                    @unless ($transaction->isTransfer())
                        <x-select name="account_id" label="Compte" :options="$accountOptions->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.$a->scopeLabel().')'])" :value="$transaction->account_id" :placeholder="false" />
                    @endunless
                @else
                    <div class="field"><span class="label">Montant</span><strong><x-money-amount :value="$transaction->amount" signed /></strong></div>
                    <div class="field"><span class="label">Date · compte</span><span>{{ $transaction->occurred_on->format('d/m/Y') }} · {{ $transaction->account?->name }}</span></div>
                @endunless
                @unless ($transaction->isTransfer())
                    <div class="field @error('category_id') has-error @enderror">
                        <label for="category_id">Catégorie</label>
                        <select id="category_id" name="category_id" @unless ($fromQuotes) data-filter-options @endunless>
                            <option value="">— Sans catégorie —</option>
                            @foreach ($categoryOptions->when($fromQuotes, fn ($c) => $c->where('type', $type)) as $category)
                                <option value="{{ $category->id }}" data-when="{{ $category->type }}" @selected((string) old('category_id', $transaction->category_id) === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id')<span class="error">{{ $message }}</span>@enderror
                    </div>
                @endunless
                <x-field name="label" label="Libellé" :value="$transaction->label" required maxlength="160" />
                <x-field name="notes" label="Note" :value="$transaction->notes" maxlength="500" class="span-2" />
            </div>
            <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
    </div>

    @unless ($fromQuotes)
        <form method="POST" action="{{ route('transactions.destroy', $transaction) }}" data-confirm="Supprimer ce mouvement{{ $transaction->isTransfer() ? ' (les deux côtés du virement)' : '' }} ?" style="margin-top:1rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger-outline" type="submit"><x-icon name="trash" /> Supprimer</button>
        </form>
    @endunless
@endsection
