@extends('layouts.app', ['title' => 'Objectifs'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Objectifs</h1>
            <p>Mettre de l'argent de côté pour un projet, encaisser un montant chaque mois, garder un résultat positif, ne pas trop dépenser.</p>
        </div>
    </div>

    @if ($goals->isEmpty())
        <div class="card empty"><x-icon name="target" /><h2>Aucun objectif</h2><p>Ajoutez votre premier objectif ci-dessous.</p></div>
    @else
        <div class="grid grid-2">
            @foreach ($goals as $item)
                @php $goal = $item['goal']; @endphp
                <div class="card">
                    <div class="card-head">
                        <h2>{{ $goal->name }}</h2>
                        @if ($item['percent'] >= 100 && $goal->kind !== 'depenses')<span class="badge badge-success">Atteint</span>@endif
                    </div>
                    <p class="small muted" style="margin-top:-.5rem">{{ $goal->kindLabel() }}{{ $item['period'] ? ' · '.$item['period'] : '' }}{{ $goal->kind !== 'epargne' ? ' · '.\App\Models\MoneyGoal::SCOPES[$goal->scope] : '' }}{{ $goal->account ? ' · compte '.$goal->account->name : '' }}</p>
                    <div class="goal-head"><strong style="font-size:1.25rem"><x-money-amount :value="$item['current']" /></strong><span class="muted">sur <x-money-amount :value="$item['target']" /> · {{ $item['percent'] }} %</span></div>
                    <div class="progress is-{{ $item['status'] }}"><span style="width:{{ min(100, $item['percent']) }}%"></span></div>
                    @if ($item['hint'])<p class="money-note" style="margin-top:0">{{ $item['hint'] }}</p>@endif

                    @if ($goal->isSaving() && ! $goal->account_id)
                        <form method="POST" action="{{ route('goals.contribute', $goal) }}" class="budget-form" style="margin-top:.75rem">
                            @csrf
                            <input type="text" name="contribution" inputmode="decimal" placeholder="ex. 50 ou -20" aria-label="Montant mis de côté">
                            <button class="btn btn-sm" type="submit">Ajouter</button>
                        </form>
                        @error('contribution')<span class="error small">{{ $message }}</span>@enderror
                    @endif

                    <details style="margin-top:.75rem">
                        <summary class="small">Modifier</summary>
                        <form method="POST" action="{{ route('goals.update', $goal) }}" style="margin-top:.75rem">
                            @csrf
                            @method('PUT')
                            @include('goals._fields', ['goal' => $goal])
                            <label class="check" style="margin-top:.5rem"><input type="checkbox" name="archived" value="1"> <span>Archiver</span></label>
                            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
                        </form>
                        <form method="POST" action="{{ route('goals.destroy', $goal) }}" data-confirm="Supprimer cet objectif ?" style="margin-top:.5rem">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger-outline" type="submit">Supprimer</button>
                        </form>
                    </details>
                </div>
            @endforeach
        </div>
    @endif

    <details class="card" style="margin-top:1rem" @if ($errors->any() || $goals->isEmpty()) open @endif>
        <summary><strong>+ Nouvel objectif</strong></summary>
        <form method="POST" action="{{ route('goals.store') }}" style="margin-top:1rem">
            @csrf
            @include('goals._fields', ['goal' => null])
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="target" /> Créer l'objectif</button></div>
        </form>
    </details>

    @if ($archived->isNotEmpty())
        <div class="card">
            <h2>Archivés</h2>
            <ul class="stat-list">
                @foreach ($archived as $goal)
                    <li><span>{{ $goal->name }}{{ $goal->achieved_at ? ' · atteint le '.$goal->achieved_at->format('d/m/Y') : '' }}</span>
                        <form method="POST" action="{{ route('goals.destroy', $goal) }}" data-confirm="Supprimer cet objectif ?">@csrf @method('DELETE')<button class="icon-btn icon-btn-sm" type="submit"><x-icon name="trash" /><span class="visually-hidden">Supprimer</span></button></form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
