@extends('layouts.app', ['title' => 'Patrimoine'])

@php
    $labels = array_map(fn ($p) => ucfirst(rtrim($p['date']->locale('fr')->isoFormat('MMM YY'), '.')), $history);
    $ticks = [0, 4, 8, count($labels) - 1];
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Patrimoine</h1>
            <p>Ce que vous avez (comptes, épargne, placements, ce qu'on vous doit) moins ce que vous devez (dettes, crédits), mois après mois.</p>
        </div>
    </div>

    <div class="grid money-mini">
        <div class="card kpi kpi-accent"><span class="label">Patrimoine net</span><span class="value"><x-money-amount :value="$today['net']" /></span></div>
        <div class="card kpi"><span class="label">Depuis {{ $since->locale('fr')->isoFormat('MMMM YYYY') }}</span><span class="value {{ $change >= 0 ? 'm-pos' : 'm-neg' }}"><x-money-amount :value="$change" signed /></span></div>
        <div class="card kpi"><span class="label">Par mois en moyenne</span><span class="value"><x-money-amount :value="(int) round($change / max(1, count($history) - 1))" signed /></span></div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Patrimoine net sur 12 mois</h2></div>
        @include('_line', [
            'title' => 'Patrimoine net à la fin de chaque mois',
            'labels' => $labels,
            'ticks' => $ticks,
            'series' => [['label' => 'Patrimoine net', 'class' => 'line-accent', 'values' => array_column($history, 'net')]],
        ])
        <details style="margin-top:.75rem">
            <summary class="small">Voir les chiffres mois par mois</summary>
            <div class="table-wrap" style="margin-top:.5rem">
                <table class="table">
                    <thead><tr><th>Fin de</th><th class="num">Comptes</th><th class="num">Prêts</th><th class="num">Crédits</th><th class="num">Net</th></tr></thead>
                    <tbody>
                        @foreach (array_reverse($history) as $point)
                            <tr>
                                <td>{{ ucfirst($point['date']->locale('fr')->isoFormat('MMMM YYYY')) }}</td>
                                <td class="num"><x-money-amount :value="$point['accounts']" /></td>
                                <td class="num"><x-money-amount :value="$point['loans']" /></td>
                                <td class="num"><x-money-amount :value="-$point['credits']" /></td>
                                <td class="num"><strong><x-money-amount :value="$point['net']" /></strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>

    <div class="card">
        <h2>Aujourd'hui</h2>
        @if ($breakdown->isEmpty())
            <p class="muted" style="margin:0">Ajoutez vos comptes et leur solde pour voir votre patrimoine.</p>
        @else
            <ul class="stat-list">
                @foreach ($breakdown as $row)
                    <li><a href="{{ $row['url'] }}">{{ $row['label'] }}</a><strong><x-money-amount :value="$row['amount']" signed /></strong></li>
                @endforeach
                <li><strong>Patrimoine net</strong><strong><x-money-amount :value="$today['net']" /></strong></li>
            </ul>
        @endif
        <p class="small muted" style="margin-bottom:0">Un compte compte à partir de sa date de départ. Les crédits de la page Crédits sont comptés à part : si le même prêt existe aussi comme compte « crédit », archivez l'un des deux.</p>
    </div>
@endsection
