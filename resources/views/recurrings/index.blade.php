@extends('layouts.app', ['title' => 'Dépenses fixes'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Dépenses et revenus fixes</h1>
            <p>Loyer, crédit, assurances, abonnements, salaire… Notés tout seuls à leur date, et comptés dans le solde prévu en fin de mois.</p>
        </div>
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">Sorties fixes / mois</span><span class="value m-neg"><x-money-amount :value="$monthlyOut" /></span></div>
        <div class="card kpi"><span class="label">Entrées fixes / mois</span><span class="value m-pos"><x-money-amount :value="$monthlyIn" /></span></div>
        <div class="card kpi"><span class="label">Reste / mois</span><span class="value"><x-money-amount :value="$monthlyIn - $monthlyOut" signed /></span>@if ($monthlySaved)<span class="delta">dont <x-money-amount :value="$monthlySaved" /> mis de côté</span>@endif</div>
    </div>

    @if ($suggestions->isNotEmpty())
        <div class="card suggest-card" id="abonnements">
            <div class="card-head"><h2>Abonnements repérés</h2><span class="badge badge-info">{{ $suggestions->count() }}</span></div>
            <p class="small muted" style="margin-top:0">Ces dépenses reviennent régulièrement dans vos relevés et mouvements. Ajoutées aux Fixes, elles sont prévues dans le solde de fin de mois et le calendrier (les prochains prélèvements importés seront reconnus, sans doublon).</p>
            <ul class="suggest-list">
                @foreach ($suggestions as $s)
                    <li>
                        <form method="POST" action="{{ route('recurrings.adopt') }}" class="suggest-form">
                            @csrf
                            <input type="hidden" name="key" value="{{ $s['key'] }}">
                            <div class="suggest-main">
                                <input class="suggest-label" type="text" name="sub_label" value="{{ $s['label'] }}" maxlength="160" required aria-label="Nom de l'abonnement">
                                <span class="small muted">{{ \App\Models\MoneyRecurring::FREQUENCIES[$s['frequency']] }} · {{ $s['account']->name }} · vu {{ $s['count'] }} fois, dernier le {{ $s['last_on']->format('d/m/Y') }} · prochain vers le {{ $s['next_on']->format('d/m') }}</span>
                            </div>
                            <strong class="suggest-amount"><x-money-amount :value="$s['amount']" signed /></strong>
                            <div class="suggest-actions">
                                <button class="btn btn-sm" type="submit"><x-icon name="plus" /> Ajouter aux Fixes</button>
                                <button class="btn btn-sm btn-secondary" type="submit" formaction="{{ route('recurrings.dismiss') }}" formnovalidate>Ce n'en est pas un</button>
                            </div>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($recurrings->isNotEmpty())
        <ul class="list">
            @foreach ($recurrings as $recurring)
                <li>
                    <details class="list-item" style="display:block">
                        <summary style="display:flex;align-items:center;gap:.75rem;list-style:none;cursor:pointer">
                            <span class="tx-icon" style="background:{{ $recurring->category?->color ?? '#B0BEC5' }}" aria-hidden="true"><x-icon name="repeat" /></span>
                            <span class="list-main">
                                <strong>{{ $recurring->label }}</strong>
                                <span class="muted small">{{ $recurring->frequencyLabel() }} · prochain le {{ $recurring->next_on->format('d/m/Y') }} · {{ $recurring->account?->name }}{{ $recurring->isTransfer() ? ' → '.$recurring->toAccount?->name : '' }}{{ $recurring->active ? '' : ' · en pause' }}</span>
                            </span>
                            <span class="list-meta"><strong>@if ($recurring->isTransfer())<x-money-amount :value="$recurring->amount" />@else<x-money-amount :value="$recurring->amount" signed />@endif</strong></span>
                        </summary>
                        <form method="POST" action="{{ route('recurrings.update', $recurring) }}" style="margin-top:1rem">
                            @csrf
                            @method('PUT')
                            @include('recurrings._fields', ['recurring' => $recurring])
                            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
                        </form>
                        <div class="money-actions" style="margin-top:.5rem">
                            <form method="POST" action="{{ route('recurrings.update', $recurring) }}">@csrf @method('PUT')<input type="hidden" name="toggle" value="1"><button class="btn btn-sm btn-secondary" type="submit">{{ $recurring->active ? 'Mettre en pause' : 'Réactiver' }}</button></form>
                            <form method="POST" action="{{ route('recurrings.destroy', $recurring) }}" data-confirm="Supprimer « {{ $recurring->label }} » ?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger-outline" type="submit">Supprimer</button></form>
                        </div>
                    </details>
                </li>
            @endforeach
        </ul>
    @endif

    <details class="card" @if ($errors->any() || $recurrings->isEmpty()) open @endif>
        <summary><strong>+ Ajouter une dépense, un revenu ou un virement fixe</strong></summary>
        <form method="POST" action="{{ route('recurrings.store') }}" style="margin-top:1rem">
            @csrf
            @include('recurrings._fields', ['recurring' => null])
            <div class="form-actions"><button class="btn" type="submit">Ajouter</button></div>
        </form>
    </details>
@endsection
