@extends('layouts.app', ['title' => 'Objectifs'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Objectifs</h1>
            <p>Un projet (nouvelle voiture, vacances…) avec son compte séparé et ses dépôts, ou un objectif du mois : encaisser, gagner, ne pas trop dépenser.</p>
        </div>
    </div>

    @if ($goals->isEmpty())
        <div class="card empty"><x-icon name="target" /><h2>Aucun objectif</h2><p>Ajoutez votre premier objectif ci-dessous, par exemple « Nouvelle voiture ».</p></div>
    @else
        <div class="grid grid-2">
            @foreach ($goals as $item)
                @php $goal = $item['goal']; @endphp
                <a class="card goal-card kpi-link" href="{{ $goal->isSaving() ? route('goals.show', $goal) : '#objectif-'.$goal->id }}" id="objectif-{{ $goal->id }}">
                    <div class="goal-title">
                        <span class="tx-icon" style="background:{{ $goal->isSaving() ? '#0EA5E9' : 'var(--accent-strong)' }}" aria-hidden="true"><x-icon :name="$goal->iconName()" /></span>
                        <span class="list-main"><strong>{{ $goal->name }}</strong>
                            <span class="small muted">
                                @if ($goal->isSaving())
                                    {{ $goal->deadline ? 'Pour le '.$goal->deadline->format('d/m/Y') : 'Sans date' }}{{ $goal->account ? ' · compte séparé' : '' }}
                                @else
                                    {{ $goal->kindLabel() }} · {{ $item['period'] }} · {{ \App\Models\MoneyGoal::SCOPES[$goal->scope] }}
                                @endif
                            </span>
                        </span>
                        @if ($item['percent'] >= 100 && $goal->kind !== 'depenses')<span class="badge badge-success">Atteint</span>@endif
                    </div>
                    <div class="goal-head" style="margin-top:.75rem"><strong style="font-size:1.25rem"><x-money-amount :value="$item['current']" /></strong><span class="muted">sur <x-money-amount :value="$item['target']" /> · {{ $item['percent'] }} %</span></div>
                    <div class="progress is-{{ $item['status'] }}"><span style="width:{{ min(100, $item['percent']) }}%"></span></div>
                    @if ($item['hint'])<p class="money-note" style="margin-top:0">{{ $item['hint'] }}</p>@endif
                </a>
                @unless ($goal->isSaving())
                    <details class="card" style="margin-top:-.5rem">
                        <summary class="small">Modifier « {{ $goal->name }} »</summary>
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
                @endunless
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
                    <li><a href="{{ $goal->isSaving() ? route('goals.show', $goal) : '#' }}">{{ $goal->name }}{{ $goal->achieved_at ? ' · atteint le '.$goal->achieved_at->format('d/m/Y') : '' }}</a>
                        <form method="POST" action="{{ route('goals.destroy', $goal) }}" data-confirm="Supprimer cet objectif ?">@csrf @method('DELETE')<button class="icon-btn icon-btn-sm" type="submit"><x-icon name="trash" /><span class="visually-hidden">Supprimer</span></button></form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
