@extends('layouts.app', ['title' => 'Bilan de la semaine'])

@php
    $from = \Illuminate\Support\Carbon::parse($data['from']);
    $to = \Illuminate\Support\Carbon::parse($data['to']);
    $quotes = $data['quotes'] ?? [];
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Semaine du {{ $from->locale('fr')->isoFormat('D MMMM') }} au {{ $to->locale('fr')->isoFormat('D MMMM YYYY') }}</h1>
            <p>Bilan figé le {{ \Illuminate\Support\Carbon::parse($data['made_at'] ?? $report->updated_at)->format('d/m/Y à H:i') }}.</p>
        </div>
        <div class="money-actions">
            @if ($previous)<a class="btn btn-sm btn-secondary" href="{{ route('reports.show', $previous) }}"><x-icon name="chevron-left" /> Précédente</a>@endif
            @if ($next)<a class="btn btn-sm btn-secondary" href="{{ route('reports.show', $next) }}">Suivante</a>@endif
        </div>
    </div>

    <div class="grid money-kpis">
        <div class="card kpi" style="border-top:4px solid var(--success)"><span class="label">Gagné</span><span class="value m-pos"><x-money-amount :value="$data['all']['income']" /></span></div>
        <div class="card kpi" style="border-top:4px solid var(--danger)"><span class="label">Dépensé</span><span class="value m-neg"><x-money-amount :value="$data['all']['expense']" /></span></div>
        <div class="card kpi"><span class="label">Résultat</span><span class="value"><x-money-amount :value="$data['all']['net']" signed /></span></div>
        <div class="card kpi kpi-accent"><span class="label">Solde total le {{ $to->format('d/m') }}</span><span class="value"><x-money-amount :value="$data['balance']" /></span></div>
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <h2>Perso / pro</h2>
            <ul class="stat-list">
                <li><span>Perso : gagné / dépensé</span><strong><x-money-amount :value="$data['perso']['income']" /> / <x-money-amount :value="$data['perso']['expense']" /></strong></li>
                <li><span>Résultat perso</span><strong><x-money-amount :value="$data['perso']['net']" signed /></strong></li>
                <li><span>Pro : gagné / dépensé</span><strong><x-money-amount :value="$data['pro']['income']" /> / <x-money-amount :value="$data['pro']['expense']" /></strong></li>
                <li><span>Résultat pro</span><strong><x-money-amount :value="$data['pro']['net']" signed /></strong></li>
            </ul>
        </div>
        <div class="card">
            <h2>App de devis</h2>
            <ul class="stat-list">
                <li><span>Paiements reçus</span><strong><x-money-amount :value="$quotes['collected'] ?? 0" /></strong></li>
                <li><span>Frais des chantiers</span><strong><x-money-amount :value="$quotes['spent'] ?? 0" /></strong></li>
                <li><span>Devis acceptés ({{ $quotes['accepted'] ?? 0 }})</span><strong><x-money-amount :value="$quotes['accepted_amount'] ?? 0" /></strong></li>
                <li><span>Restait à encaisser</span><strong><x-money-amount :value="$quotes['to_collect'] ?? 0" /></strong></li>
            </ul>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <h2>Plus grosses dépenses</h2>
            @if (empty($data['top_expenses']))
                <p class="muted" style="margin:0">Aucune dépense cette semaine.</p>
            @else
                <ul class="stat-list">
                    @foreach ($data['top_expenses'] as $item)
                        <li><span><span class="swatch-dot" style="background:{{ $item['color'] }}"></span>{{ $item['name'] }}</span><strong><x-money-amount :value="$item['amount']" /></strong></li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card">
            <h2>Comptes</h2>
            <ul class="stat-list">
                @foreach ($data['accounts'] as $account)
                    <li><span>{{ $account['name'] }}</span><strong><x-money-amount :value="$account['balance']" /></strong></li>
                @endforeach
            </ul>
        </div>
    </div>

    @if (! empty($data['goals']))
        <div class="card" style="margin-top:1rem">
            <h2>Objectifs ce jour-là</h2>
            @foreach ($data['goals'] as $goal)
                <div style="margin-bottom:.6rem">
                    <div class="goal-head"><strong>{{ $goal['name'] }}</strong><span class="small">{{ $goal['percent'] }} %</span></div>
                    <div class="progress is-{{ $goal['status'] }}"><span style="width:{{ min(100, $goal['percent']) }}%"></span></div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
