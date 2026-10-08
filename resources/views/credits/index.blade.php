@extends('layouts.app', ['title' => 'Crédits'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Crédits</h1>
            <p>Ce qu'il reste à rembourser, la date de fin et les intérêts encore à payer, calculés à partir de votre contrat.</p>
        </div>
    </div>

    @if ($active->isNotEmpty())
        <div class="grid money-mini">
            <div class="card kpi"><span class="label">Reste à rembourser</span><span class="value m-neg"><x-money-amount :value="$remaining" /></span></div>
            <div class="card kpi"><span class="label">Mensualités</span><span class="value"><x-money-amount :value="$monthly" /></span><span class="delta">par mois, assurance comprise</span></div>
            <div class="card kpi"><span class="label">Intérêts restants</span><span class="value"><x-money-amount :value="$interest" /></span></div>
        </div>
        <ul class="list">
            @foreach ($active as $credit)
                @php $percent = $credit->percentRepaid(); @endphp
                <li>
                    <a class="list-item" href="{{ route('credits.show', $credit) }}">
                        <span class="tx-icon" style="background:var(--accent-strong)" aria-hidden="true"><x-icon name="loan" /></span>
                        <span class="list-main">
                            <strong>{{ $credit->name }}</strong>
                            <span class="muted small">Fin en {{ $credit->endDate()->locale('fr')->isoFormat('MMMM YYYY') }} · {{ $percent }} % remboursé</span>
                            <span class="progress is-success" style="margin:.375rem 0 0"><span style="width:{{ $percent }}%"></span></span>
                        </span>
                        <span class="list-meta"><strong><x-money-amount :value="$credit->remaining()" /></strong><span class="small muted">restant</span></span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <details class="card" @if ($errors->any() || $active->isEmpty()) open @endif>
        <summary><strong>+ Ajouter un crédit</strong></summary>
        <form method="POST" action="{{ route('credits.store') }}" style="margin-top:1rem">
            @csrf
            @include('credits._fields', ['credit' => null])
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="loan" /> Ajouter</button></div>
        </form>
        <p class="small muted" style="margin-bottom:0">Les chiffres sont dans votre offre de prêt ou votre tableau d'amortissement. Un prêt à taux variable ou remboursé en avance peut s'écarter un peu du calcul.</p>
    </details>

    @if ($done->isNotEmpty())
        <details class="card">
            <summary><strong>Remboursés ou archivés ({{ $done->count() }})</strong></summary>
            <ul class="stat-list" style="margin-top:.5rem">
                @foreach ($done as $credit)
                    <li><a href="{{ route('credits.show', $credit) }}">{{ $credit->name }}</a><span class="small muted">fin {{ $credit->endDate()->format('m/Y') }}</span></li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
