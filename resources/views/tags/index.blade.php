@extends('layouts.app', ['title' => 'Chantiers et projets'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Chantiers et projets</h1>
            <p>Mettez une étiquette (« Dupont », « Salle de bain ») sur les dépenses et les paiements d'un chantier ou d'un projet, quelle que soit leur catégorie : vous voyez son coût total, ce qui est encaissé et la marge.</p>
        </div>
    </div>

    @if ($active->isNotEmpty())
        <ul class="list">
            @foreach ($active as $row)
                @php
                    $tag = $row['tag'];
                    $net = $row['income'] - $row['expense'];
                @endphp
                <li>
                    <a class="list-item" href="{{ route('tags.show', $tag) }}">
                        <span class="tx-icon" style="background:{{ $tag->color }}" aria-hidden="true"><x-icon name="tag" /></span>
                        <span class="list-main">
                            <strong>{{ $tag->name }}</strong>
                            <span class="muted small">{{ $row['count'] }} mouvement{{ $row['count'] > 1 ? 's' : '' }}
                                @if ($row['expense']) · dépensé <span class="m-amt">{{ \App\Support\Money::format($row['expense']) }}</span>@endif
                                @if ($row['income']) · encaissé <span class="m-amt">{{ \App\Support\Money::format($row['income']) }}</span>@endif
                            </span>
                            @if ($tag->budget)
                                @php $percent = (int) round($row['expense'] * 100 / max(1, $tag->budget)); @endphp
                                <span class="progress is-{{ $percent > 100 ? 'danger' : ($percent >= 85 ? 'warning' : 'success') }}" style="margin:.375rem 0 0"><span style="width:{{ min(100, $percent) }}%"></span></span>
                            @endif
                        </span>
                        <span class="list-meta">
                            @if ($row['income'])
                                <strong><x-money-amount :value="$net" signed /></strong><span class="small muted">marge</span>
                            @else
                                <strong><x-money-amount :value="$row['expense']" /></strong><span class="small muted">coût</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <details class="card" @if ($errors->any() || $active->isEmpty()) open @endif>
        <summary><strong>+ Nouveau chantier ou projet</strong></summary>
        <form method="POST" action="{{ route('tags.store') }}" style="margin-top:1rem">
            @csrf
            <div class="form-grid cols-2">
                <x-field name="name" label="Nom" required maxlength="60" placeholder="ex. Chantier Dupont, Salle de bain" />
                <x-field name="budget" label="Budget prévu (€)" inputmode="decimal" placeholder="facultatif" />
                <x-field name="tag_notes" label="Note" maxlength="500" class="span-2" placeholder="adresse, devis n°…" />
            </div>
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="tag" /> Créer</button></div>
        </form>
        <p class="small muted" style="margin-bottom:0">Astuce : l'étiquette se crée aussi toute seule quand vous l'écrivez dans le champ « Chantier / projet » d'un mouvement.</p>
    </details>

    @if ($archived->isNotEmpty())
        <details class="card">
            <summary><strong>Terminés ({{ $archived->count() }})</strong></summary>
            <ul class="stat-list" style="margin-top:.5rem">
                @foreach ($archived as $row)
                    <li><a href="{{ route('tags.show', $row['tag']) }}">{{ $row['tag']->name }}</a><strong><x-money-amount :value="$row['income'] - $row['expense']" signed /></strong></li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
