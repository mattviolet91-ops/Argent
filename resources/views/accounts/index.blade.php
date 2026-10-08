@extends('layouts.app', ['title' => 'Comptes'])

@section('content')
    @include('_nav')

    <div class="grid money-kpis" style="margin-bottom:1rem">
        <div class="card kpi kpi-accent"><span class="label">Patrimoine net</span><span class="value"><x-money-amount :value="$totals['all']" /></span><span class="delta">Perso <x-money-amount :value="$totals['perso']" /> · Pro <x-money-amount :value="$totals['pro']" /></span></div>
        <div class="card kpi"><span class="label">Disponible</span><span class="value"><x-money-amount :value="$totals['courant']" /></span><span class="delta">comptes courants, espèces</span></div>
        <div class="card kpi" style="border-top:4px solid var(--success)"><span class="label">Épargne et placements</span><span class="value m-pos"><x-money-amount :value="$totals['epargne']" /></span></div>
        <div class="card kpi" style="border-top:4px solid var(--danger)"><span class="label">Reste à rembourser</span><span class="value m-neg"><x-money-amount :value="-$totals['dette']" /></span><span class="delta">crédits, cartes à débit différé</span></div>
    </div>

    @foreach ($groups as $group)
        <div class="money-row" style="margin:.25rem 0 .5rem"><h2 style="margin:0">{{ $group['label'] }}</h2><strong><x-money-amount :value="$group['accounts']->sum('current_balance')" /></strong></div>
        <ul class="list">
            @foreach ($group['accounts'] as $account)
                <li>
                    <a class="list-item" href="{{ route('accounts.show', $account) }}">
                        <span class="tx-icon" style="background:{{ $account->color ?? '#8A99A6' }}" aria-hidden="true"><x-icon :name="$account->icon()" /></span>
                        <span class="list-main"><strong>{{ $account->name }}</strong><span class="muted small">{{ $account->kindLabel() }} · {{ $account->scopeLabel() }}</span></span>
                        <span class="list-meta"><strong><x-money-amount :value="$account->current_balance" /></strong>@if ($account->isDebt())<span class="small muted">à rembourser</span>@endif</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endforeach

    <details class="card" @if ($errors->any()) open @endif>
        <summary><strong>+ Ajouter un compte</strong> <span class="muted small">(livret, assurance-vie, bourse, crypto, crédit, espèces…)</span></summary>
        <form method="POST" action="{{ route('accounts.store') }}" style="margin-top:1rem">
            @csrf
            @include('accounts._fields', ['account' => null])
            <div class="form-actions"><button class="btn" type="submit">Ajouter le compte</button></div>
        </form>
    </details>

    @if ($archived->isNotEmpty())
        <div class="card">
            <h2>Comptes archivés</h2>
            <ul class="stat-list">
                @foreach ($archived as $account)
                    <li><a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a><strong><x-money-amount :value="$account->current_balance" /></strong></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
