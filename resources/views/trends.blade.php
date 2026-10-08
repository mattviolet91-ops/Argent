@extends('layouts.app', ['title' => 'Tendances'])

@php
    use App\Services\MoneyTrendService;
    $monthName = today()->locale('fr')->isoFormat('MMMM');
@endphp

@section('content')
    @include('_nav')
    @include('_scope')

    <div class="page-head">
        <div>
            <h1>Tendances</h1>
            <p>Ce que vous avez dépensé du 1er au {{ $day }} {{ $monthName }}, comparé à d'habitude : la moyenne des {{ MoneyTrendService::MONTHS }} mois d'avant sur les mêmes jours.</p>
        </div>
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">Dépensé ce mois-ci</span><span class="value"><x-money-amount :value="$total['current']" /></span></div>
        <div class="card kpi"><span class="label">D'habitude à cette date</span><span class="value"><x-money-amount :value="$total['usual']" /></span></div>
        <div class="card kpi"><span class="label">Écart</span>
            <span class="value {{ $total['change'] === null ? '' : ($total['change'] > 0 ? 'm-neg' : 'm-pos') }}">{{ $total['change'] === null ? '—' : ($total['change'] > 0 ? '+' : '').$total['change'].' %' }}</span>
            @if ($total['change'] !== null)<span class="delta">{{ $total['change'] > 0 ? 'de plus' : ($total['change'] < 0 ? 'de moins' : 'pareil') }} que d'habitude</span>@endif
        </div>
    </div>

    @if ($notable->isNotEmpty())
        <div class="card">
            <h2>À retenir</h2>
            <ul class="trend-list">
                @foreach ($notable as $row)
                    <li class="{{ $row['up'] ? 'is-up' : 'is-down' }}">
                        <span class="trend-arrow" aria-hidden="true">{{ $row['up'] ? '▲' : '▼' }}</span>
                        <span><strong>{{ $row['sentence'] }}</strong>
                            <span class="small muted m-amt">{{ \App\Support\Money::format($row['current']) }} contre {{ \App\Support\Money::format($row['usual']) }} d'habitude à cette date.</span></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif ($total['usual'] > 0)
        <div class="alert alert-info">Rien d'inhabituel ce mois-ci : vos dépenses suivent vos habitudes.</div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Par catégorie</h2><span class="small muted">ce mois-ci · d'habitude</span></div>
        @if ($categories->isEmpty())
            <p class="muted" style="margin:0">Pas encore assez de dépenses pour comparer. Les tendances apparaissent après un mois ou deux de mouvements (saisis ou importés).</p>
        @else
            <ul class="trend-table">
                @foreach ($categories as $row)
                    @php $notableRow = $row['id'] !== null && MoneyTrendService::isNotable($row); @endphp
                    <li>
                        <div class="trend-row-head">
                            <a href="{{ route('transactions.index', ['categorie' => $row['id'] ?? 'aucune', 'periode' => 'mois']) }}"><span class="swatch-dot" style="background:{{ $row['color'] }}"></span>{{ $row['name'] }}</a>
                            <span class="small">
                                <strong class="m-amt">{{ \App\Support\Money::format($row['current']) }}</strong>
                                <span class="muted m-amt">· {{ \App\Support\Money::format($row['usual']) }}</span>
                                @if ($row['change'] !== null)
                                    <span class="badge {{ $notableRow ? ($row['change'] > 0 ? 'badge-danger' : 'badge-success') : '' }}">{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }} %</span>
                                @elseif ($row['current'] > 0)
                                    <span class="badge">nouveau</span>
                                @endif
                            </span>
                        </div>
                        <div class="trend-bars" aria-hidden="true">
                            <span class="trend-now" style="width:{{ $row['current'] ? max(1, (int) round($row['current'] * 100 / $max)) : 0 }}%;background:{{ $row['color'] }}"></span>
                            <span class="trend-usual" style="width:{{ $row['usual'] ? max(1, (int) round($row['usual'] * 100 / $max)) : 0 }}%"></span>
                        </div>
                        @if ($row['usual_month'] > 0)
                            <span class="small muted">Sur un mois entier, d'habitude : <span class="m-amt">{{ \App\Support\Money::format($row['usual_month']) }}</span></span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="cal-legend small muted"><span class="cal-key is-now"></span> ce mois-ci <span class="cal-key is-usual"></span> d'habitude à cette date</p>
        @endif
    </div>

    @if ($yearAgo)
        @php $lastMonth = $yearAgo['month']->locale('fr')->isoFormat('MMMM YYYY'); @endphp
        <div class="card">
            <div class="card-head"><h2>Comparé à {{ $lastMonth }}</h2><span class="small muted">du 1er au {{ $day }}</span></div>
            <p style="margin-top:0">Dépensé : <strong class="m-amt">{{ \App\Support\Money::format($yearAgo['total']['current']) }}</strong> cette année contre <span class="m-amt">{{ \App\Support\Money::format($yearAgo['total']['last']) }}</span> en {{ $lastMonth }}
                @if ($yearAgo['total']['change'] !== null)<span class="badge {{ $yearAgo['total']['change'] > 0 ? 'badge-danger' : 'badge-success' }}">{{ $yearAgo['total']['change'] > 0 ? '+' : '' }}{{ $yearAgo['total']['change'] }} %</span>@endif
            </p>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Catégorie</th><th class="num">{{ today()->year }}</th><th class="num">{{ today()->year - 1 }}</th><th class="num">Écart</th></tr></thead>
                    <tbody>
                        @foreach ($yearAgo['categories']->take(12) as $row)
                            <tr>
                                <td><span class="swatch-dot" style="background:{{ $row['color'] }}"></span>{{ $row['name'] }}</td>
                                <td class="num"><x-money-amount :value="$row['current']" /></td>
                                <td class="num"><x-money-amount :value="$row['last']" /></td>
                                <td class="num">{{ $row['change'] === null ? '—' : ($row['change'] > 0 ? '+' : '').$row['change'].' %' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
