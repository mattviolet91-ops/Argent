@extends('layouts.app', ['title' => 'Résumé'])

@php
    use App\Services\MoneyStatsService;
    use App\Support\Money;
    $periodLabel = match ($period) {
        'semaine' => 'cette semaine',
        'annee' => 'cette année',
        'perso' => 'du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
        default => 'ce mois-ci',
    };
    $delta = function (int $now, int $before, bool $higherIsGood) {
        $change = MoneyStatsService::change($now, $before);
        if ($change === null) {
            return null;
        }
        $good = $higherIsGood ? $change >= 0 : $change <= 0;

        return ['text' => ($change > 0 ? '▲ +' : ($change < 0 ? '▼ ' : '= ')).$change.' %', 'class' => $change === 0 ? '' : ($good ? 'is-good' : 'is-bad')];
    };
    $scopeName = ['all' => 'perso + pro', 'perso' => 'perso', 'pro' => 'pro'][$scope];
@endphp

@section('content')
    @include('_nav')
    @include('_scope')
    @include('_period', ['periods' => \App\Http\Controllers\DashboardController::PERIODS])

    @if ($alerts)
        <div class="money-alerts" role="status" aria-label="Alertes">
            @foreach ($alerts as $alert)
                <a class="alert alert-{{ $alert['level'] === 'danger' ? 'error' : 'warning' }} money-alert" href="{{ $alert['url'] }}">
                    <strong>{{ $alert['title'] }}</strong> {{ $alert['text'] }} <span class="m-amt">{{ $alert['details'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($subscriptions)
        <a class="alert alert-info money-alert" href="{{ route('recurrings.index') }}#abonnements">
            <strong>{{ $subscriptions }} abonnement{{ $subscriptions > 1 ? 's' : '' }} repéré{{ $subscriptions > 1 ? 's' : '' }}</strong> dans vos relevés : {{ $subscriptions > 1 ? 'les ' : 'l\'' }}ajouter aux Fixes ?
        </a>
    @endif

    @if ($totals['income'] === 0 && $totals['expense'] === 0 && $latest->isEmpty())
        <div class="card getting-started" style="margin-bottom:1rem">
            <h2>Bienvenue dans votre app Argent</h2>
            <p class="muted">Ajoutez une dépense avec le bouton « + Ajouter », importez un relevé de votre banque, ou réglez le solde de vos comptes.</p>
            <div class="money-actions">
                <button class="btn" type="button" data-open-sheet="money-add"><x-icon name="plus" /> Ajouter</button>
                <a class="btn btn-secondary" href="{{ route('import.create') }}"><x-icon name="upload" /> Importer un relevé</a>
                <a class="btn btn-secondary" href="{{ route('accounts.index') }}"><x-icon name="wallet" /> Mes comptes</a>
            </div>
        </div>
    @endif

    <div class="grid money-kpis">
        <a class="card kpi kpi-accent kpi-link" href="{{ route('accounts.index') }}">
            <span class="label">Solde {{ $scopeName }}</span>
            <span class="value"><x-money-amount :value="$balance" /></span>
            <span class="delta">Fin du mois estimée : <x-money-amount :value="$forecast" /></span>
        </a>
        <a class="card kpi kpi-link" style="border-top:4px solid var(--success)" href="{{ route('transactions.index', ['type' => 'income', 'periode' => $period, 'du' => $period === 'perso' ? $from->toDateString() : null, 'au' => $period === 'perso' ? $to->toDateString() : null]) }}">
            <span class="label">Gagné {{ $periodLabel }}</span>
            <span class="value m-pos"><x-money-amount :value="$totals['income']" /></span>
            @if ($d = $delta($totals['income'], $previous['income'], true))<span class="delta {{ $d['class'] }}">{{ $d['text'] }} vs avant</span>@endif
            @if ($lastYear && ($d = $delta($totals['income'], $lastYear['income'], true)))<span class="delta {{ $d['class'] }}">{{ $d['text'] }} vs {{ $lastYearLabel }}</span>@endif
        </a>
        <a class="card kpi kpi-link" style="border-top:4px solid var(--danger)" href="{{ route('transactions.index', ['type' => 'expense', 'periode' => $period, 'du' => $period === 'perso' ? $from->toDateString() : null, 'au' => $period === 'perso' ? $to->toDateString() : null]) }}">
            <span class="label">Dépensé {{ $periodLabel }}</span>
            <span class="value m-neg"><x-money-amount :value="$totals['expense']" /></span>
            @if ($d = $delta($totals['expense'], $previous['expense'], false))<span class="delta {{ $d['class'] }}">{{ $d['text'] }} vs avant</span>@endif
            @if ($lastYear && ($d = $delta($totals['expense'], $lastYear['expense'], false)))<span class="delta {{ $d['class'] }}">{{ $d['text'] }} vs {{ $lastYearLabel }}</span>@endif
        </a>
        <div class="card kpi" style="border-top:4px solid {{ $totals['net'] >= 0 ? 'var(--success)' : 'var(--danger)' }}">
            <span class="label">{{ $totals['net'] >= 0 ? 'Gagné' : 'Perdu' }} au final</span>
            <span class="value"><x-money-amount :value="$totals['net']" signed /></span>
            @if ($totals['income'] > 0)
                <span class="delta">{{ $totals['net'] >= 0 ? 'Vous gardez '.(int) round($totals['net'] * 100 / $totals['income']).' %' : 'Vous dépensez '.(int) round($totals['expense'] * 100 / $totals['income']).' %' }} de ce qui est entré</span>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:1rem">
        <div class="card-head"><h2>Entrées et sorties sur 12 mois</h2><a class="small" href="{{ route('reports.index') }}">Bilans</a></div>
        @include('_bars', ['series' => $monthly])
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <div class="card-head"><h2>Où part l'argent</h2><span class="small muted">{{ $periodLabel }}</span></div>
            @if ($categories->isEmpty())
                <p class="muted" style="margin:0">Aucune dépense sur cette période.</p>
            @else
                <div class="donut-wrap">
                    @include('_donut', ['items' => $categories, 'total' => $totals['expense']])
                    <ul class="cat-list">
                        @foreach ($categories->take(7) as $item)
                            <li>
                                <span class="swatch-dot" style="background:{{ $item['color'] }}"></span>
                                <a href="{{ route('transactions.index', ['categorie' => $item['id'] ?? 'aucune', 'periode' => $period, 'du' => $period === 'perso' ? $from->toDateString() : null, 'au' => $period === 'perso' ? $to->toDateString() : null]) }}">{{ $item['name'] }}</a>
                                <strong><x-money-amount :value="$item['amount']" /></strong>
                                <span class="cat-bar" aria-hidden="true"><span style="width:{{ max(2, (int) round($item['amount'] * 100 / max(1, $categories->first()['amount']))) }}%;background:{{ $item['color'] }}"></span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="card">
            <div class="card-head"><h2>Objectifs</h2><a class="small" href="{{ route('goals.index') }}">{{ $goals->isEmpty() ? 'Ajouter' : 'Tout voir' }}</a></div>
            @forelse ($goals->take(4) as $item)
                <div style="margin-bottom:.75rem">
                    <div class="goal-head"><strong>{{ $item['goal']->name }}</strong><span class="small">{{ $item['percent'] }} %</span></div>
                    <div class="progress is-{{ $item['status'] }}"><span style="width:{{ min(100, $item['percent']) }}%"></span></div>
                    <div class="goal-meta"><span><x-money-amount :value="$item['current']" /> / <x-money-amount :value="$item['target']" /></span><span>{{ $item['hint'] }}</span></div>
                </div>
            @empty
                <p class="muted" style="margin:0 0 .75rem">Fixez-vous un objectif : mettre de côté pour un projet, encaisser un montant par mois, ne pas dépasser un budget…</p>
                <a class="btn btn-secondary btn-sm" href="{{ route('goals.index') }}"><x-icon name="target" /> Créer un objectif</a>
            @endforelse
        </div>
    </div>

    @if ($budgets->isNotEmpty())
        <div class="card" style="margin-top:1rem">
            <div class="card-head"><h2>Budgets de {{ today()->locale('fr')->isoFormat('MMMM') }}</h2><a class="small" href="{{ route('categories.index') }}">Modifier</a></div>
            @foreach ($budgets as $row)
                @php $tone = $row['percent'] > 100 ? 'danger' : ($row['percent'] >= 85 ? 'warning' : 'success'); @endphp
                <div style="margin-bottom:.6rem">
                    <div class="goal-head"><span><span class="swatch-dot" style="background:{{ $row['category']->color }}"></span>{{ $row['category']->name }}</span><span class="small"><x-money-amount :value="$row['spent']" /> / <x-money-amount :value="$row['budget']" /></span></div>
                    <div class="progress is-{{ $tone }}"><span style="width:{{ min(100, $row['percent']) }}%"></span></div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($trends->isNotEmpty() || $people->isNotEmpty())
        <div class="grid grid-2" style="margin-top:1rem">
            @if ($trends->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Tendances du mois</h2><a class="small" href="{{ route('trends') }}">Détails</a></div>
                    <ul class="trend-list">
                        @foreach ($trends as $row)
                            <li class="{{ $row['up'] ? 'is-up' : 'is-down' }}">
                                <span class="trend-arrow" aria-hidden="true">{{ $row['up'] ? '▲' : '▼' }}</span>
                                <span>{{ $row['sentence'] }} <span class="small muted m-amt">{{ Money::format($row['current']) }} contre {{ Money::format($row['usual']) }} à cette date.</span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($people->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Qui me doit quoi</h2><a class="small" href="{{ route('loans.index') }}">Tout voir</a></div>
                    <ul class="stat-list">
                        @foreach ($people->take(4) as $person)
                            @php $owed = $person->balance(); @endphp
                            <li><a href="{{ route('loans.show', $person) }}">{{ $person->name }} <span class="small muted">· {{ $owed > 0 ? 'vous doit' : 'vous lui devez' }}</span>@if ($person->isOverdue()) <span class="badge badge-danger">en retard</span>@endif</a>
                                <strong class="{{ $owed > 0 ? 'm-pos' : 'm-neg' }}"><x-money-amount :value="abs($owed)" /></strong></li>
                        @endforeach
                    </ul>
                    <p class="money-note">On vous doit <strong><x-money-amount :value="$owedToMe" /></strong>@if ($iOwe) · vous devez <strong><x-money-amount :value="$iOwe" /></strong>@endif</p>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-2" style="margin-top:1rem">
        @if ($scope !== 'perso' && ! $quotes)
            <div class="card">
                <div class="card-head"><h2>App de devis</h2></div>
                <p class="muted" style="margin-top:0">Reliez l'app à votre app de devis : les paiements reçus et les frais des chantiers arriveront tout seuls chaque semaine.</p>
                <a class="btn btn-secondary btn-sm" href="{{ route('settings') }}#devis"><x-icon name="repeat" /> Relier l'app de devis</a>
            </div>
        @elseif ($quotes)
            <div class="card">
                <div class="card-head"><h2>App de devis</h2><span class="small muted">{{ $periodLabel }}</span></div>
                <ul class="stat-list">
                    <li><span>Encaissé (paiements reçus)</span><strong><x-money-amount :value="$quotes['collected']" /></strong></li>
                    <li><span>Frais des chantiers</span><strong><x-money-amount :value="-$quotes['spent']" /></strong></li>
                    <li><span>Gain des chantiers</span><strong><x-money-amount :value="$quotes['gain']" signed /></strong></li>
                    <li><a href="{{ $devisUrl }}/factures?status=unpaid" rel="noopener">Reste à encaisser</a><strong><x-money-amount :value="$quotes['to_collect']" /></strong></li>
                    <li><a href="{{ $devisUrl }}/devis" rel="noopener">Devis en attente ({{ $quotes['pending_quotes'] }})</a><strong><x-money-amount :value="$quotes['pending_amount']" /></strong></li>
                </ul>
                <div class="money-row" style="margin-top:.75rem">
                    <span class="small muted">
                        @if ($syncAccount)
                            Copié chaque lundi sur « {{ $syncAccount->name }} »{{ $lastSync ? ' · dernière mise à jour '.$lastSync->locale('fr')->diffForHumans() : '' }}
                        @else
                            Choisissez le compte pro dans les réglages.
                        @endif
                    </span>
                    <form method="POST" action="{{ route('sync') }}" data-busy="Mise à jour…">
                        @csrf
                        <button class="btn btn-sm btn-secondary" type="submit"><x-icon name="repeat" /> Mettre à jour</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>D'ici la fin du mois</h2><a class="small" href="{{ route('calendar') }}">Calendrier</a></div>
            @if ($upcoming->isEmpty())
                <p class="muted" style="margin:0">Rien de prévu. Ajoutez vos dépenses fixes (loyer, abonnements, crédit…) pour voir venir.</p>
            @else
                <ul class="stat-list">
                    @foreach ($upcoming as $item)
                        <li><span>{{ $item['date']->format('d/m') }} · {{ $item['recurring']->label }}{{ $item['recurring']->isTransfer() ? ' → '.$item['recurring']->toAccount?->name : '' }}</span><strong>@if ($item['recurring']->isTransfer())<x-money-amount :value="$item['recurring']->amount" />@else<x-money-amount :value="$item['recurring']->amount" signed />@endif</strong></li>
                    @endforeach
                </ul>
            @endif
            <p class="money-note">Solde estimé fin {{ today()->locale('fr')->isoFormat('MMMM') }} : <strong><x-money-amount :value="$forecast" /></strong></p>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <div class="card-head"><h2>Comptes</h2><a class="small" href="{{ route('accounts.index') }}">Gérer</a></div>
            <ul class="stat-list">
                @foreach ($accounts as $account)
                    <li><a href="{{ route('accounts.show', $account) }}"><span class="swatch-dot" style="background:{{ $account->color ?? '#8A99A6' }}"></span>{{ $account->name }} <span class="badge">{{ $account->scopeLabel() }}</span></a><strong><x-money-amount :value="$account->current_balance" /></strong></li>
                @endforeach
            </ul>
        </div>

        <div class="card">
            <div class="card-head"><h2>Derniers mouvements</h2><a class="small" href="{{ route('transactions.index') }}">Tout voir</a></div>
            @if ($latest->isEmpty())
                <p class="muted" style="margin:0">Aucun mouvement pour l'instant.</p>
            @else
                <ul class="stat-list">
                    @foreach ($latest as $t)
                        <li>
                            <a href="{{ route('transactions.edit', $t) }}" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <span class="swatch-dot" style="background:{{ $t->category?->color ?? ($t->isTransfer() ? 'var(--accent-strong)' : '#B0BEC5') }}"></span>{{ $t->label }}
                                <span class="small muted">· {{ $t->occurred_on->format('d/m') }}</span>
                            </a>
                            <strong><x-money-amount :value="$t->amount" signed /></strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

@endsection
