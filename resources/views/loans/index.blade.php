@extends('layouts.app', ['title' => 'Qui me doit quoi'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Qui me doit quoi</h1>
            <p>L'argent prêté ou avancé à un ami, un client, un associé… et les remboursements. Avec une date, vous êtes prévenu si le remboursement tarde.</p>
        </div>
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">On vous doit</span><span class="value m-pos"><x-money-amount :value="$owedToMe" /></span></div>
        <div class="card kpi"><span class="label">Vous devez</span><span class="value {{ $iOwe ? 'm-neg' : '' }}"><x-money-amount :value="$iOwe" /></span></div>
    </div>

    @if ($open->isNotEmpty())
        <ul class="list">
            @foreach ($open as $person)
                @php $balance = $person->balance(); @endphp
                <li>
                    <a class="list-item" href="{{ route('loans.show', $person) }}">
                        <span class="tx-icon loan-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($person->name, 0, 1)) }}</span>
                        <span class="list-main">
                            <strong>{{ $person->name }}</strong>
                            <span class="muted small">{{ $person->relationLabel() }}
                                @if ($person->isOverdue())
                                    · <span class="badge badge-danger">en retard depuis le {{ $person->due_on->format('d/m') }}</span>
                                @elseif ($person->due_on && $balance > 0)
                                    · à rembourser avant le {{ $person->due_on->format('d/m/Y') }}
                                @endif
                            </span>
                        </span>
                        <span class="list-meta">
                            <strong class="{{ $balance > 0 ? 'm-pos' : 'm-neg' }}"><x-money-amount :value="abs($balance)" /></strong>
                            <span class="small muted">{{ $balance > 0 ? 'vous doit' : 'vous lui devez' }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <details class="card" @if ($errors->any() || $open->isEmpty()) open @endif>
        <summary><strong>+ Noter un prêt, une avance ou un remboursement</strong></summary>
        <form method="POST" action="{{ route('loans.store') }}" style="margin-top:1rem">
            @csrf
            <div class="form-grid cols-2">
                <div class="field @error('name') has-error @enderror">
                    <label for="name">Qui ?</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" list="people" maxlength="80" autocomplete="off" required placeholder="Prénom, nom ou entreprise">
                    <datalist id="people">
                        @foreach ($names as $name)<option value="{{ $name }}"></option>@endforeach
                    </datalist>
                    @error('name')<span class="error">{{ $message }}</span>@enderror
                </div>
                <x-select name="relation" label="C'est" :options="\App\Models\MoneyPerson::RELATIONS" value="ami" :placeholder="false" />
            </div>
            <div style="margin-top:.75rem">@include('loans._entry', ['type' => 'pret'])</div>
            <x-field name="due_on" label="À rembourser avant le (facultatif)" type="date" hint="Un rappel arrive 3 jours avant, et si c'est en retard." />
            <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
    </details>

    @if ($settled->isNotEmpty())
        <details class="card">
            <summary><strong>Comptes soldés ({{ $settled->count() }})</strong></summary>
            <ul class="stat-list" style="margin-top:.5rem">
                @foreach ($settled as $person)
                    <li><a href="{{ route('loans.show', $person) }}">{{ $person->name }} <span class="small muted">· {{ $person->relationLabel() }}</span></a><span class="small muted">{{ $person->archived_at ? 'archivé' : 'quittes' }}</span></li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
