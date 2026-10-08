@extends('layouts.app', ['title' => 'Mouvements'])

@php
    $periods = ['semaine' => 'Semaine', 'mois' => 'Mois', 'annee' => 'Année', 'tout' => 'Tout'];
    $byDay = $transactions->getCollection()->groupBy(fn ($t) => $t->occurred_on->toDateString());
@endphp

@section('content')
    @include('_nav')
    @include('_scope')
    @include('_period')

    @php $filtered = $filters['q'] || $filters['compte'] || $filters['categorie'] !== '' || $filters['type'] !== ''; @endphp
    <details class="card money-filters" @if ($filtered) open @endif>
    <summary>Filtrer, rechercher{{ $filtered ? ' (filtre actif)' : '' }}</summary>
    <form method="GET" action="{{ route('transactions.index') }}" class="filters" style="margin-top:1rem">
        <input type="hidden" name="periode" value="{{ $period }}">
        @if ($period === 'perso')<input type="hidden" name="du" value="{{ $from->toDateString() }}"><input type="hidden" name="au" value="{{ $to->toDateString() }}">@endif
        <div class="form-grid cols-2">
            <div class="field"><label for="q">Rechercher</label><input id="q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Libellé, note…"></div>
            <div class="field">
                <label for="compte">Compte</label>
                <select id="compte" name="compte">
                    <option value="">Tous</option>
                    @foreach ($allAccounts as $account)
                        <option value="{{ $account->id }}" @selected($filters['compte'] === $account->id)>{{ $account->name }}{{ $account->archived_at ? ' (archivé)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="categorie">Catégorie</label>
                <select id="categorie" name="categorie">
                    <option value="">Toutes</option>
                    <option value="aucune" @selected($filters['categorie'] === 'aucune')>Sans catégorie</option>
                    @foreach ($categoryOptions as $category)
                        <option value="{{ $category->id }}" @selected($filters['categorie'] === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">Tous</option>
                    @foreach (\App\Http\Controllers\TransactionController::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-actions" style="margin-top:.75rem">
            <button class="btn btn-secondary" type="submit">Filtrer</button>
            @if ($filtered)<a class="btn btn-secondary" href="{{ route('transactions.index', ['periode' => $period]) }}">Effacer</a>@endif
        </div>
    </form>
    </details>
    <div class="money-actions" style="margin-bottom:1rem">
        <a class="btn btn-secondary btn-sm" href="{{ route('import.create') }}"><x-icon name="upload" /> Importer un relevé</a>
        <a class="btn btn-secondary btn-sm" href="{{ route('export') }}"><x-icon name="file" /> Tout télécharger</a>
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">Entrées</span><span class="value m-pos"><x-money-amount :value="$income" /></span></div>
        <div class="card kpi"><span class="label">Sorties</span><span class="value m-neg"><x-money-amount :value="$expense" /></span></div>
        <div class="card kpi"><span class="label">Résultat</span><span class="value"><x-money-amount :value="$income - $expense" signed /></span></div>
    </div>

    @if ($transactions->isEmpty())
        <div class="card empty"><x-icon name="wallet" /><h2>Aucun mouvement</h2>
            <p>Rien sur cette période avec ces filtres.</p>
            <p><button class="btn" type="button" data-open-sheet="money-add"><x-icon name="plus" /> Ajouter</button></p>
        </div>
    @else
        <ul class="list">
            @foreach ($byDay as $day => $items)
                <li class="tx-day"><span>{{ ucfirst(\Illuminate\Support\Carbon::parse($day)->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}</span><x-money-amount :value="$items->reject(fn ($t) => $t->isTransfer())->sum('amount')" signed /></li>
                @foreach ($items as $t)
                    <li>
                        <a class="list-item" href="{{ route('transactions.edit', $t) }}">
                            <span class="tx-icon" style="background:{{ $t->isTransfer() ? 'var(--accent-strong)' : ($t->category?->color ?? '#B0BEC5') }}" aria-hidden="true">
                                @if ($t->isTransfer())<x-icon name="swap" />@elseif ($t->amount > 0)<x-icon name="arrow-down" />@else<x-icon name="arrow-up" />@endif
                            </span>
                            <span class="list-main">
                                <strong>{{ $t->label }}</strong>
                                <span class="muted small">{{ $t->category?->name ?? ($t->isTransfer() ? 'Virement' : 'Sans catégorie') }} · {{ $t->account?->name }}@if ($t->source !== 'manual') · {{ \App\Models\MoneyTransaction::SOURCES[$t->source] ?? '' }}@endif</span>
                            </span>
                            <span class="list-meta"><strong><x-money-amount :value="$t->amount" signed /></strong></span>
                        </a>
                    </li>
                @endforeach
            @endforeach
        </ul>
        {{ $transactions->links('components.pagination') }}
    @endif

@endsection
