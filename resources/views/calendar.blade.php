@extends('layouts.app', ['title' => 'Calendrier'])

@php
    // Montant court pour les cases : « 1 235 » (euros arrondis).
    $short = fn (int $cents) => number_format(abs($cents) / 100, 0, ',', "\u{202F}");
    $monthName = ucfirst($month->locale('fr')->isoFormat('MMMM YYYY'));
    $isCurrent = $month->isSameMonth(today());
    $tags = ['expense' => 'badge-danger', 'income' => 'badge-success', 'transfer' => 'badge-info', 'warranty' => 'badge-warning', 'loan' => 'badge-info'];
@endphp

@section('content')
    @include('_nav')
    @include('_scope')

    <div class="cal-head">
        <a class="icon-btn" href="{{ route('calendar', ['mois' => $previous->format('Y-m')]) }}" title="Mois précédent"><x-icon name="chevron-left" /><span class="visually-hidden">Mois précédent</span></a>
        <h1>{{ $monthName }}</h1>
        <a class="icon-btn" href="{{ route('calendar', ['mois' => $next->format('Y-m')]) }}" title="Mois suivant"><x-icon name="chevron-right" /><span class="visually-hidden">Mois suivant</span></a>
        @unless ($isCurrent)
            <a class="btn btn-sm btn-secondary" href="{{ route('calendar') }}">Aujourd'hui</a>
        @endunless
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">Dépensé{{ $isCurrent ? ' à ce jour' : '' }}</span><span class="value m-neg"><x-money-amount :value="$spent" /></span></div>
        <div class="card kpi"><span class="label">Gagné{{ $isCurrent ? ' à ce jour' : '' }}</span><span class="value m-pos"><x-money-amount :value="$earned" /></span></div>
        @if ($upcoming->isNotEmpty() || $month->isFuture() || $isCurrent)
            <div class="card kpi"><span class="label">Encore prévu</span><span class="value"><x-money-amount :value="$comingIn - $comingOut" signed /></span>
                <span class="delta">dépenses fixes <x-money-amount :value="-$comingOut" /> · entrées <x-money-amount :value="$comingIn" /></span></div>
        @endif
    </div>

    <div class="card cal-card">
        <div class="cal-grid">
            @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $name)
                <span class="cal-dow" aria-hidden="true">{{ $name }}</span>
            @endforeach
            @foreach ($weeks as $week)
                @foreach ($week as $day)
                    @if ($day === null)
                        <span class="cal-day is-empty" aria-hidden="true"></span>
                    @else
                        @php
                            $date = $day['date'];
                            $heat = $day['spent'] > 0 ? max(.12, min(1, $day['spent'] / $maxSpent)) : 0;
                            $label = $date->locale('fr')->isoFormat('dddd D MMMM')
                                .($day['spent'] ? ', dépensé '.\App\Support\Money::plain($day['spent']) : '')
                                .($day['earned'] ? ', gagné '.\App\Support\Money::plain($day['earned']) : '')
                                .($day['events'] ? ', '.$day['events'].' échéance'.($day['events'] > 1 ? 's' : '') : '');
                        @endphp
                        <a class="cal-day {{ $date->isToday() ? 'is-today' : '' }} {{ $selected && $date->isSameDay($selected) ? 'is-selected' : '' }} {{ $date->isAfter(today()) ? 'is-future' : '' }}"
                            href="{{ route('calendar', ['mois' => $month->format('Y-m'), 'jour' => $date->toDateString()]) }}#jour"
                            style="--heat: {{ round($heat, 2) }}" aria-label="{{ $label }}">
                            <span class="cal-num">{{ $date->day }}</span>
                            @if ($day['spent'])<span class="cal-out m-amt">−{{ $short($day['spent']) }}</span>@endif
                            @if ($day['earned'])<span class="cal-in m-amt">+{{ $short($day['earned']) }}</span>@endif
                            @if ($day['events'])<span class="cal-dots" aria-hidden="true">@for ($i = 0; $i < min(3, $day['events']); $i++)<i></i>@endfor</span>@endif
                        </a>
                    @endif
                @endforeach
            @endforeach
        </div>
        <p class="cal-legend small muted"><span class="cal-key is-out"></span> dépenses du jour (plus c'est foncé, plus c'est gros) <span class="cal-key is-dot"></span> échéance à venir</p>
    </div>

    @if ($selected)
        <div class="card" id="jour">
            <div class="card-head"><h2>{{ ucfirst($selected->locale('fr')->isoFormat('dddd D MMMM')) }}</h2>
                @if ($selected->lte(today()))<a class="small" href="{{ route('transactions.index', ['periode' => 'perso', 'du' => $selected->toDateString(), 'au' => $selected->toDateString()]) }}">Dans Mouvements</a>@endif
            </div>
            @if ($dayTransactions->isEmpty() && $dayEvents->isEmpty())
                <p class="muted" style="margin:0">{{ $selected->isAfter(today()) ? 'Rien de prévu ce jour-là.' : 'Aucun mouvement ce jour-là.' }}</p>
            @else
                <ul class="stat-list">
                    @foreach ($dayTransactions as $t)
                        <li>
                            <a href="{{ route('transactions.edit', $t) }}" class="cal-line">
                                <span class="swatch-dot" style="background:{{ $t->category?->color ?? ($t->isTransfer() ? 'var(--accent-strong)' : '#B0BEC5') }}"></span>{{ $t->label }}
                                <span class="small muted">· {{ $t->category?->name ?? ($t->isTransfer() ? 'Virement' : 'Sans catégorie') }} · {{ $t->account?->name }}</span>
                                @if ($t->occurred_on->isAfter(today()))<span class="badge badge-info">prévu</span>@endif
                            </a>
                            <strong><x-money-amount :value="$t->amount" :signed="! $t->isTransfer()" /></strong>
                        </li>
                    @endforeach
                    @foreach ($dayEvents as $event)
                        <li>
                            <a href="{{ $event['url'] }}" class="cal-line"><span class="badge {{ $tags[$event['kind']] ?? '' }}">{{ $event['tag'] }}</span> {{ $event['label'] }}</a>
                            @if ($event['amount'] !== null)<strong><x-money-amount :value="$event['amount']" :signed="$event['signed']" /></strong>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($upcoming->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>À venir en {{ $month->locale('fr')->isoFormat('MMMM') }}</h2><a class="small" href="{{ route('recurrings.index') }}">Fixes</a></div>
            <ul class="stat-list">
                @foreach ($upcoming->take(15) as $event)
                    <li>
                        <a href="{{ $event['url'] }}" class="cal-line">{{ $event['date']->format('d/m') }} · <span class="badge {{ $tags[$event['kind']] ?? '' }}">{{ $event['tag'] }}</span> {{ $event['label'] }}</a>
                        @if ($event['amount'] !== null)<strong><x-money-amount :value="$event['amount']" :signed="$event['signed']" /></strong>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
