@extends('layouts.app', ['title' => 'Puis-je me le permettre ?'])

@php
    use App\Support\Money;
    $verdicts = [
        'success' => ['Oui, sans souci', 'alert-success'],
        'warning' => ['Possible, mais attention', 'alert-warning'],
        'danger' => ['Mieux vaut attendre', 'alert-error'],
    ];
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Puis-je me le permettre ?</h1>
            <p>Indiquez un achat : l'app regarde ce qui est prévu sur le compte (dépenses fixes, prélèvements, dépôts…) et vous dit s'il passe. Rien n'est enregistré.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('afford') }}" class="card">
        <div class="field">
            <label for="montant">Prix de l'achat (€)</label>
            <input id="montant" class="amount-input" type="text" name="montant" inputmode="decimal" placeholder="0,00" value="{{ $amount ? Money::format($amount, false) : '' }}" required autocomplete="off">
        </div>
        <div class="form-grid cols-2" style="margin-top:.75rem">
            <div class="field">
                <label for="compte">Payé avec</label>
                <select id="compte" name="compte">
                    @foreach ($accounts as $option)
                        <option value="{{ $option->id }}" @selected($account?->id === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="le">Le</label>
                <input id="le" type="date" name="le" value="{{ $date->toDateString() }}">
            </div>
            <div class="field">
                <label for="fois">Paiement</label>
                <select id="fois" name="fois">
                    @foreach (\App\Http\Controllers\AffordController::INSTALMENTS as $n => $text)
                        <option value="{{ $n }}" @selected($times === $n)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="categorie">Catégorie <span class="muted small">(pour le budget)</span></label>
                <select id="categorie" name="categorie">
                    <option value="">—</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->id }}" @selected($category?->id === $option->id)>{{ $option->name }}{{ $option->monthly_budget ? ' · budget '.Money::plain($option->monthly_budget) : '' }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="help" /> Vérifier</button></div>
    </form>

    @if ($result)
        @php
            [$verdict, $class] = $verdicts[$result['level']];
            $with = $result['with'];
            $without = $result['without'];
            $step = max(1, (int) floor(count($with['days']) / 4));
            $labels = array_map(fn ($d) => $d['date']->format('d/m'), $with['days']);
            $ticks = array_values(array_unique([0, $step, $step * 2, $step * 3, count($labels) - 1]));
        @endphp
        <div class="alert {{ $class }} afford-verdict" role="status">
            <strong>{{ $verdict }}</strong>
            <span>
                @if ($with['min'] < 0)
                    « {{ $account->name }} » passerait à <span class="m-amt">{{ Money::format($with['min']) }}</span> le {{ $with['min_on']->format('d/m') }}{{ $with['min_label'] ? ' (après « '.$with['min_label'].' »)' : '' }}.
                @elseif ($result['threshold'] !== null && $with['min'] < $result['threshold'])
                    Le compte descendrait à <span class="m-amt">{{ Money::format($with['min']) }}</span> le {{ $with['min_on']->format('d/m') }}, sous votre seuil d'alerte (<span class="m-amt">{{ Money::format($result['threshold']) }}</span>).
                @else
                    Au plus bas, il resterait <span class="m-amt">{{ Money::format($with['min']) }}</span> sur « {{ $account->name }} » (le {{ $with['min_on']->format('d/m') }}).
                @endif
            </span>
        </div>

        <div class="card">
            <div class="card-head"><h2>Solde à venir de « {{ $account->name }} »</h2></div>
            <p class="chart-legend"><span><span class="key"></span>Avec l'achat</span><span><span class="key is-muted"></span>Sans l'achat</span></p>
            @include('_line', [
                'title' => 'Solde à venir avec et sans l\'achat',
                'labels' => $labels,
                'ticks' => $ticks,
                'series' => [
                    ['label' => 'Sans l\'achat', 'class' => 'line-muted', 'values' => array_column($without['days'], 'balance')],
                    ['label' => 'Avec l\'achat', 'class' => 'line-accent', 'values' => array_column($with['days'], 'balance')],
                ],
            ])
            <ul class="stat-list" style="margin-top:.75rem">
                <li><span>Solde aujourd'hui</span><strong><x-money-amount :value="$result['start']" /></strong></li>
                <li><span>Au plus bas, sans l'achat</span><strong><x-money-amount :value="$without['min']" /> <span class="small muted">le {{ $without['min_on']->format('d/m') }}</span></strong></li>
                <li><span>Au plus bas, avec l'achat</span><strong class="{{ $with['min'] < 0 ? 'm-neg' : '' }}"><x-money-amount :value="$with['min']" /> <span class="small muted">le {{ $with['min_on']->format('d/m') }}</span></strong></li>
                @if ($times > 1)
                    <li><span>Paiements</span><span class="small" style="text-align:right">{{ $result['payments']->map(fn ($p) => $p['date']->format('d/m').' : '.Money::plain(-$p['amount']))->implode(' · ') }}</span></li>
                @endif
                @if ($result['this_month'])
                    <li><span>Solde fin {{ today()->locale('fr')->isoFormat('MMMM') }} (tous comptes)</span><strong><x-money-amount :value="$result['forecast_without'] - $result['this_month']" /> <span class="small muted">au lieu de <x-money-amount :value="$result['forecast_without']" /></span></strong></li>
                @endif
            </ul>
        </div>

        <div class="card">
            <h2>À savoir</h2>
            <ul class="afford-notes">
                @if ($result['budget'])
                    @php $b = $result['budget']; @endphp
                    <li class="{{ $b['after'] > $b['budget'] ? 'is-bad' : 'is-good' }}">Budget « {{ $b['name'] }} » : <span class="m-amt">{{ Money::format($b['spent']) }}</span> dépensés ce mois-là, <span class="m-amt">{{ Money::format($b['after']) }}</span> avec l'achat sur <span class="m-amt">{{ Money::format($b['budget']) }}</span>{{ $b['after'] > $b['budget'] ? ' : budget dépassé.' : '.' }}</li>
                @endif
                @if ($result['months_of_savings'] !== null)
                    <li>En moyenne, il vous reste <span class="m-amt">{{ Money::format($result['saved']) }}</span> par mois (3 derniers mois) : cet achat en représente <strong>{{ str_replace('.', ',', (string) $result['months_of_savings']) }} mois</strong>.</li>
                @elseif ($result['saved'] <= 0)
                    <li class="is-bad">Ces 3 derniers mois, vous avez dépensé plus que ce qui est entré : prudence avec les achats non nécessaires.</li>
                @endif
                @if ($times > 1)
                    <li>En {{ $times }} fois, vérifiez les frais : un paiement fractionné est souvent un crédit (avec intérêts).</li>
                @endif
                <li class="muted">Calcul fait avec ce qui est noté dans l'app : dépenses fixes, mouvements prévus. Une dépense non notée n'y est pas.</li>
            </ul>
        </div>
    @endif
@endsection
