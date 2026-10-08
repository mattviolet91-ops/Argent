@extends('layouts.app', ['title' => $account->name])

@php
    $values = collect($points)->pluck('balance')->filter(fn ($v) => $v !== null);
    $min = min(0, (int) $values->min());
    $max = max(1, (int) $values->max());
    $range = max(1, $max - $min);
    $w = 360; $h = 120; $step = $w / max(1, count($points) - 1);
    $coords = collect($points)->map(fn ($p, $i) => $p['balance'] === null ? null : [round($i * $step, 1), round(8 + ($h - 16) * (1 - ($p['balance'] - $min) / $range), 1)])->filter();
    $zeroY = round(8 + ($h - 16) * (1 - (0 - $min) / $range), 1);
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1 style="display:flex;align-items:center;gap:.5rem"><span class="swatch-dot" style="background:{{ $account->color ?? '#8A99A6' }};width:1rem;height:1rem"></span>{{ $account->name }}</h1>
            <p>{{ $account->kindLabel() }} · {{ $account->scopeLabel() }}{{ $account->archived_at ? ' · archivé' : '' }}</p>
        </div>
        <div class="kpi" style="text-align:right"><span class="label">Solde aujourd'hui</span><span class="value"><x-money-amount :value="$balance" /></span></div>
    </div>

    @if ($coords->count() > 1)
        <div class="card">
            <div class="card-head"><h2>Évolution du solde</h2><span class="small muted">fin de chaque mois</span></div>
            <svg class="money-chart" viewBox="0 0 {{ $w }} {{ $h + 20 }}" role="img" aria-label="Évolution du solde sur 12 mois">
                @if ($min < 0)<line class="zero" x1="0" y1="{{ $zeroY }}" x2="{{ $w }}" y2="{{ $zeroY }}" />@endif
                <polyline class="line" points="{{ $coords->map(fn ($c) => $c[0].','.$c[1])->implode(' ') }}" />
                @foreach ($points as $i => $p)
                    @if ($p['balance'] !== null)
                        <circle class="dot" cx="{{ round($i * $step, 1) }}" cy="{{ round(8 + ($h - 16) * (1 - ($p['balance'] - $min) / $range), 1) }}" r="3"><title>{{ $p['day']->format('d/m/Y') }} : {{ \App\Support\Money::plain($p['balance']) }}</title></circle>
                    @endif
                    @if ($i % 2 === 1 || $i === count($points) - 1)
                        <text class="tick" x="{{ min($w - 14, max(14, round($i * $step, 1))) }}" y="{{ $h + 16 }}" text-anchor="middle">{{ rtrim($p['day']->locale('fr')->isoFormat('MMM'), '.') }}</text>
                    @endif
                @endforeach
            </svg>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Derniers mouvements</h2><a class="small" href="{{ route('transactions.index', ['compte' => $account->id, 'periode' => 'tout']) }}">Tous ({{ $count }})</a></div>
        @if ($latest->isEmpty())
            <p class="muted" style="margin:0">Aucun mouvement sur ce compte.</p>
        @else
            <ul class="stat-list">
                @foreach ($latest as $t)
                    <li><a href="{{ route('transactions.edit', $t) }}">{{ $t->occurred_on->format('d/m') }} · {{ $t->label }}</a><strong><x-money-amount :value="$t->amount" signed /></strong></li>
                @endforeach
            </ul>
        @endif
    </div>

    <details class="card" @if ($errors->any()) open @endif>
        <summary><strong>Modifier le compte</strong></summary>
        <form method="POST" action="{{ route('accounts.update', $account) }}" style="margin-top:1rem">
            @csrf
            @method('PUT')
            @include('accounts._fields', ['account' => $account])
            <label class="check" style="margin-top:.75rem"><input type="checkbox" name="archived" value="1" @checked($account->archived_at)> <span>Archiver (masquer, garder l'historique)</span></label>
            <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
        <form method="POST" action="{{ route('accounts.destroy', $account) }}" data-confirm="Supprimer ce compte ? S'il a des mouvements, il sera seulement archivé." style="margin-top:.75rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger-outline btn-sm" type="submit"><x-icon name="trash" /> Supprimer</button>
        </form>
    </details>
@endsection
