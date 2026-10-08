@extends('layouts.app', ['title' => $credit->name])

@php
    $percent = $credit->percentRepaid();
    $left = $credit->months - $paid;
@endphp

@section('content')
    @include('_nav')

    <p class="small"><a href="{{ route('credits.index') }}"><x-icon name="chevron-left" class="icon icon-inline" /> Crédits</a></p>

    <div class="card">
        <div class="card-head"><h1 style="margin:0">{{ $credit->name }}</h1><span class="badge {{ $remaining ? 'badge-info' : 'badge-success' }}">{{ $remaining ? $percent.' % remboursé' : 'Remboursé 🎉' }}</span></div>
        <div class="progress is-success"><span style="width:{{ $percent }}%"></span></div>
        <ul class="stat-list">
            <li><span>Reste à rembourser</span><strong><x-money-amount :value="$remaining" /></strong></li>
            <li><span>Mensualités restantes</span><strong>{{ $left }} <span class="small muted">sur {{ $credit->months }}</span></strong></li>
            <li><span>Dernière mensualité</span><strong>{{ ucfirst($credit->endDate()->locale('fr')->isoFormat('MMMM YYYY')) }}</strong></li>
            <li><span>Mensualité</span><strong><x-money-amount :value="$credit->monthly + $credit->insurance" />@if ($credit->insurance) <span class="small muted">dont <x-money-amount :value="$credit->insurance" /> d'assurance</span>@endif</strong></li>
            <li><span>Intérêts encore à payer</span><strong><x-money-amount :value="$credit->remainingInterest()" /></strong></li>
            <li><span>Coût total du crédit</span><strong><x-money-amount :value="$credit->totalInterest() + $credit->insurance * $credit->months" /> <span class="small muted">intérêts{{ $credit->insurance ? ' + assurance' : '' }}</span></strong></li>
            <li><span>Emprunté</span><strong><x-money-amount :value="$credit->principal" /> <span class="small muted">à {{ str_replace('.', ',', (string) ($credit->rate / 100)) }} %</span></strong></li>
        </ul>
    </div>

    <div class="card">
        <h2>Dans les Fixes</h2>
        @if ($credit->recurring)
            <p style="margin:0">La mensualité est notée toute seule chaque mois sur « {{ $credit->recurring->account?->name }} » (prochaine le {{ $credit->recurring->next_on->format('d/m/Y') }}). <a href="{{ route('recurrings.index') }}">Voir les Fixes</a></p>
        @else
            <p class="small muted" style="margin-top:0">Ajoutez la mensualité aux Fixes : elle sera notée toute seule et comptée dans le solde prévu.</p>
            <form method="POST" action="{{ route('credits.recurring', $credit) }}">
                @csrf
                <button class="btn btn-sm" type="submit" @disabled(! $remaining)><x-icon name="repeat" /> Ajouter la mensualité aux Fixes</button>
            </form>
            @error('credit_account')<p class="error">{{ $message }}</p>@enderror
        @endif
    </div>

    @if ($next)
        <div class="card">
            <h2>Prochaines mensualités</h2>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th class="num">Capital</th><th class="num">Intérêts</th><th class="num">Reste dû</th></tr></thead>
                    <tbody>
                        @foreach ($next as $row)
                            <tr><td>{{ $row['date']->format('d/m/Y') }}</td><td class="num"><x-money-amount :value="$row['capital']" /></td><td class="num"><x-money-amount :value="$row['interest']" /></td><td class="num"><x-money-amount :value="$row['remaining']" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <details class="card">
        <summary><strong>Année par année</strong></summary>
        <div class="table-wrap" style="margin-top:.75rem">
            <table class="table">
                <thead><tr><th>Année</th><th class="num">Payé</th><th class="num">dont intérêts</th><th class="num">Reste dû fin d'année</th></tr></thead>
                <tbody>
                    @foreach ($years as $row)
                        <tr><td>{{ $row['year'] }}</td><td class="num"><x-money-amount :value="$row['payment']" /></td><td class="num"><x-money-amount :value="$row['interest']" /></td><td class="num"><x-money-amount :value="$row['remaining']" /></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <details class="card" @if ($errors->hasAny(['name', 'principal', 'rate', 'first_due_on', 'months', 'monthly', 'insurance', 'credit_notes'])) open @endif>
        <summary><strong>Modifier</strong></summary>
        <form method="POST" action="{{ route('credits.update', $credit) }}" style="margin-top:1rem">
            @csrf
            @method('PUT')
            @include('credits._fields', ['credit' => $credit])
            <label class="check" style="margin-top:.5rem"><input type="checkbox" name="archived" value="1" @checked($credit->archived_at)> <span>Archiver (remboursé en avance, revendu…)</span></label>
            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
        </form>
        <form method="POST" action="{{ route('credits.destroy', $credit) }}" data-confirm="Supprimer ce crédit ?" style="margin-top:.5rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger-outline" type="submit">Supprimer</button>
        </form>
    </details>
@endsection
