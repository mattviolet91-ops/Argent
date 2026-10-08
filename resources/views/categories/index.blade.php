@extends('layouts.app', ['title' => 'Budgets'])

@php use App\Support\Money; @endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Budgets de {{ $month->locale('fr')->isoFormat('MMMM YYYY') }}</h1>
            <p>Fixez un montant à ne pas dépasser par mois pour chaque catégorie. La moyenne des 3 derniers mois aide à choisir.</p>
        </div>
    </div>

    @if ($budgets->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Ce mois-ci</h2><span class="small muted"><x-money-amount :value="$budgets->sum('spent')" /> / <x-money-amount :value="$budgets->sum('budget')" /></span></div>
            @foreach ($budgets as $row)
                @php $tone = $row['percent'] > 100 ? 'danger' : ($row['percent'] >= 85 ? 'warning' : 'success'); @endphp
                <div style="margin-bottom:.6rem">
                    <div class="goal-head"><span><span class="swatch-dot" style="background:{{ $row['category']->color }}"></span>{{ $row['category']->name }}</span><span class="small">{{ $row['percent'] }} %</span></div>
                    <div class="progress is-{{ $tone }}"><span style="width:{{ min(100, $row['percent']) }}%"></span></div>
                    <div class="goal-meta"><span><x-money-amount :value="$row['spent']" /> dépensés sur <x-money-amount :value="$row['budget']" /></span>
                        <span>{{ $row['budget'] >= $row['spent'] ? 'Reste ' : 'Dépassé de ' }}<x-money-amount :value="abs($row['budget'] - $row['spent'])" /></span></div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <h2>Dépenses</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Catégorie</th><th class="num">Ce mois</th><th class="num">Moy. 3 mois</th><th>Budget / mois</th></tr></thead>
                <tbody>
                    @foreach ($expenses as $category)
                        <tr>
                            <td><span class="swatch-dot" style="background:{{ $category->color }}"></span>{{ $category->name }} <span class="badge">{{ \App\Models\MoneyCategory::SCOPES[$category->scope] }}</span></td>
                            <td class="num"><x-money-amount :value="(int) ($spent[$category->id] ?? 0)" /></td>
                            <td class="num muted"><x-money-amount :value="(int) ($average[$category->id] ?? 0)" /></td>
                            <td>
                                <form method="POST" action="{{ route('categories.update', $category) }}" class="budget-form">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="monthly_budget" value="{{ $category->monthly_budget ? Money::format($category->monthly_budget, false) : '' }}" inputmode="decimal" placeholder="aucun" aria-label="Budget mensuel {{ $category->name }}">
                                    <button class="btn btn-sm btn-secondary" type="submit">OK</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('monthly_budget')<p class="error">{{ $message }}</p>@enderror
    </div>

    <div class="card">
        <h2>Revenus</h2>
        <ul class="stat-list">
            @foreach ($incomes as $category)
                <li><span><span class="swatch-dot" style="background:{{ $category->color }}"></span>{{ $category->name }} <span class="badge">{{ \App\Models\MoneyCategory::SCOPES[$category->scope] }}</span></span><strong><x-money-amount :value="(int) ($spent[$category->id] ?? 0)" /></strong></li>
            @endforeach
        </ul>
    </div>

    <details class="card" @if ($errors->hasAny(['name', 'type', 'scope'])) open @endif>
        <summary><strong>Gérer les catégories</strong> <span class="muted small">(ajouter, renommer, masquer)</span></summary>
        <form method="POST" action="{{ route('categories.store') }}" style="margin-top:1rem">
            @csrf
            <h3>Nouvelle catégorie</h3>
            <div class="form-grid cols-2">
                <x-field name="name" label="Nom" required maxlength="60" />
                <x-select name="type" label="Type" :options="\App\Models\MoneyCategory::TYPES" value="expense" :placeholder="false" />
                <x-select name="scope" label="Pour" :options="\App\Models\MoneyCategory::SCOPES" value="both" :placeholder="false" />
                <div class="field"><label for="new-color">Couleur</label><input id="new-color" type="color" name="color" value="#8A99A6"></div>
                <x-field name="monthly_budget" label="Budget / mois (€, dépenses)" inputmode="decimal" placeholder="facultatif" />
            </div>
            <div class="form-actions"><button class="btn" type="submit">Ajouter</button></div>
        </form>

        <h3 style="margin-top:1.5rem">Renommer ou masquer</h3>
        <ul class="stat-list">
            @foreach ($expenses->concat($incomes) as $category)
                <li>
                    <form method="POST" action="{{ route('categories.update', $category) }}" class="budget-form" style="flex:1">
                        @csrf
                        @method('PUT')
                        <input type="color" name="color" value="{{ $category->color }}" aria-label="Couleur" style="width:2.5rem;padding:0">
                        <input type="text" name="name" value="{{ $category->name }}" maxlength="60" aria-label="Nom" style="flex:1;width:auto">
                        <button class="btn btn-sm btn-secondary" type="submit">OK</button>
                    </form>
                    <form method="POST" action="{{ route('categories.destroy', $category) }}" data-confirm="Masquer ou supprimer « {{ $category->name }} » ?">
                        @csrf
                        @method('DELETE')
                        <button class="icon-btn icon-btn-sm" type="submit" title="Masquer"><x-icon name="trash" /><span class="visually-hidden">Masquer</span></button>
                    </form>
                </li>
            @endforeach
        </ul>
        @if ($archived->isNotEmpty())
            <h3 style="margin-top:1.5rem">Masquées</h3>
            <ul class="stat-list">
                @foreach ($archived as $category)
                    <li><span>{{ $category->name }}</span>
                        <form method="POST" action="{{ route('categories.update', $category) }}">@csrf @method('PUT')<input type="hidden" name="restore" value="1"><button class="btn btn-sm btn-secondary" type="submit">Réafficher</button></form>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
@endsection
