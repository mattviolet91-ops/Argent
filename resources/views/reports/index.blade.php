@extends('layouts.app', ['title' => 'Bilans'])

@php
    use App\Services\MoneyStatsService;
    $change = MoneyStatsService::change($yearTotals['net'], $previousYear['net']);
    $bestMonth = collect($months)->sortByDesc('net')->first();
@endphp

@section('content')
    @include('_nav')
    @include('_scope')

    <div class="chips" style="margin-bottom:1rem" role="group" aria-label="Année">
        @for ($y = today()->year; $y >= today()->year - 3; $y--)
            <a class="chip {{ $year === $y ? 'is-active' : '' }}" href="{{ request()->fullUrlWithQuery(['annee' => $y, 'page' => null]) }}">{{ $y }}</a>
        @endfor
    </div>

    <div class="grid money-kpis">
        <div class="card kpi" style="border-top:4px solid var(--success)"><span class="label">Gagné en {{ $year }}</span><span class="value m-pos"><x-money-amount :value="$yearTotals['income']" /></span></div>
        <div class="card kpi" style="border-top:4px solid var(--danger)"><span class="label">Dépensé en {{ $year }}</span><span class="value m-neg"><x-money-amount :value="$yearTotals['expense']" /></span></div>
        <div class="card kpi"><span class="label">Résultat {{ $year }}</span><span class="value"><x-money-amount :value="$yearTotals['net']" signed /></span>
            @if ($change !== null)<span class="delta {{ $change >= 0 ? 'is-good' : 'is-bad' }}">{{ $change >= 0 ? '▲ +' : '▼ ' }}{{ $change }} % vs {{ $year - 1 }} à la même date</span>@endif
        </div>
        <div class="card kpi"><span class="label">Cette semaine</span><span class="value"><x-money-amount :value="$currentWeek['net']" signed /></span><span class="delta">+<x-money-amount :value="$currentWeek['income']" /> / −<x-money-amount :value="$currentWeek['expense']" /></span></div>
    </div>

    <div class="card" style="margin-top:1rem">
        <div class="card-head"><h2>Mois par mois</h2>@if ($bestMonth && $bestMonth['net'] > 0)<span class="small muted">Meilleur mois : {{ $bestMonth['month']->locale('fr')->isoFormat('MMMM') }}</span>@endif</div>
        @include('_bars', ['series' => $months])
        <div class="table-wrap" style="margin-top:1rem">
            <table class="table">
                <thead><tr><th>Mois</th><th class="num">Gagné</th><th class="num">Dépensé</th><th class="num">Résultat</th><th class="num">Gardé</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($months) as $m)
                        <tr>
                            <td><a href="{{ route('transactions.index', ['periode' => 'perso', 'du' => $m['month']->copy()->startOfMonth()->toDateString(), 'au' => $m['month']->copy()->endOfMonth()->toDateString()]) }}">{{ ucfirst($m['month']->locale('fr')->isoFormat('MMMM')) }}</a></td>
                            <td class="num"><x-money-amount :value="$m['income']" /></td>
                            <td class="num"><x-money-amount :value="$m['expense']" /></td>
                            <td class="num"><x-money-amount :value="$m['net']" signed /></td>
                            <td class="num muted">{{ $m['income'] > 0 ? (int) round($m['net'] * 100 / $m['income']).' %' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($categories->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Plus grosses dépenses {{ $year }}</h2></div>
            <ul class="cat-list">
                @foreach ($categories as $item)
                    <li>
                        <span class="swatch-dot" style="background:{{ $item['color'] }}"></span>
                        <span>{{ $item['name'] }}</span>
                        <strong><x-money-amount :value="$item['amount']" /></strong>
                        <span class="cat-bar" aria-hidden="true"><span style="width:{{ max(2, (int) round($item['amount'] * 100 / max(1, $categories->first()['amount']))) }}%;background:{{ $item['color'] }}"></span></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Bilans de chaque semaine</h2><span class="small muted">faits chaque lundi matin</span></div>
        @if ($weeks->isEmpty())
            <p class="muted" style="margin:0">Le premier bilan sera fait lundi prochain, après la mise à jour depuis les devis.</p>
        @else
            <ul class="stat-list">
                @foreach ($weeks as $week)
                    <li>
                        <a href="{{ route('reports.show', $week) }}">Semaine du {{ $week->week_start->format('d/m') }} au {{ $week->week_start->copy()->addDays(6)->format('d/m/Y') }}</a>
                        <strong><x-money-amount :value="$week->data['all']['net'] ?? 0" signed /></strong>
                    </li>
                @endforeach
            </ul>
            {{ $weeks->links('components.pagination') }}
        @endif
    </div>
@endsection
