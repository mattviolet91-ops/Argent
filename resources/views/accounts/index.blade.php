@extends('layouts.app', ['title' => 'Comptes'])

@section('content')
    @include('_nav')

    <div class="grid money-mini">
        <div class="card kpi kpi-accent"><span class="label">Total</span><span class="value"><x-money-amount :value="$totals['all']" /></span></div>
        <div class="card kpi"><span class="label">Perso</span><span class="value"><x-money-amount :value="$totals['perso']" /></span></div>
        <div class="card kpi"><span class="label">Pro</span><span class="value"><x-money-amount :value="$totals['pro']" /></span></div>
    </div>

    <ul class="list">
        @foreach ($accounts as $account)
            <li>
                <a class="list-item" href="{{ route('accounts.show', $account) }}">
                    <span class="tx-icon" style="background:{{ $account->color ?? '#8A99A6' }}" aria-hidden="true"><x-icon name="wallet" /></span>
                    <span class="list-main"><strong>{{ $account->name }}</strong><span class="muted small">{{ $account->kindLabel() }} · {{ $account->scopeLabel() }}</span></span>
                    <span class="list-meta"><strong><x-money-amount :value="$account->current_balance" /></strong></span>
                </a>
            </li>
        @endforeach
    </ul>

    <details class="card" @if ($errors->any()) open @endif>
        <summary><strong>+ Ajouter un compte</strong> <span class="muted small">(livret, espèces, autre banque…)</span></summary>
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
