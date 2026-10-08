@extends('layouts.app', ['title' => 'Aperçu du relevé'])

@php
    $statusLabels = ['new' => null, 'known' => 'Déjà importée', 'similar' => 'Déjà notée ?', 'price' => 'Nouveau prix'];
    $selected = collect($rows)->whereIn('status', ['new', 'price']);
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Aperçu : {{ count($rows) }} opération{{ count($rows) > 1 ? 's' : '' }}</h1>
            <p>Compte « {{ $account->name }} ». Vérifiez, choisissez les catégories, puis importez les lignes cochées.</p>
        </div>
    </div>

    <div class="chips" style="margin-bottom:1rem">
        <span class="chip">{{ $counts['new'] ?? 0 }} nouvelle(s)</span>
        @if ($counts['similar'] ?? 0)<span class="chip">{{ $counts['similar'] }} ressemblant à un mouvement déjà noté (décochées)</span>@endif
        @if ($counts['known'] ?? 0)<span class="chip">{{ $counts['known'] }} déjà importée(s)</span>@endif
        @if ($counts['price'] ?? 0)<span class="chip">{{ $counts['price'] }} abonnement(s) à un nouveau prix</span>@endif
    </div>

    <form method="POST" action="{{ route('import.store') }}" data-busy="Import…">
        @csrf
        <div class="card">
            <div class="money-row" style="margin-bottom:.5rem">
                <label class="check"><input type="checkbox" data-check-all checked> <span>Tout cocher</span></label>
                <span class="small muted">Entrées : <x-money-amount :value="$selected->where('amount', '>', 0)->sum('amount')" /> · Sorties : <x-money-amount :value="-$selected->where('amount', '<', 0)->sum('amount')" /></span>
            </div>
            <div class="table-wrap">
                <table class="table import-table">
                    <thead><tr><th></th><th>Date</th><th>Libellé</th><th class="num">Montant</th><th>Catégorie</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            <tr class="{{ $row['status'] === 'known' ? 'is-known' : '' }}">
                                <td><input type="checkbox" name="import[{{ $i }}]" value="1" aria-label="Importer cette ligne" @checked(in_array($row['status'], ['new', 'price'], true)) @disabled($row['status'] === 'known') @if ($row['status'] !== 'known') data-check-item @endif></td>
                                <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                                <td>{{ $row['label'] }}
                                    @if ($statusLabels[$row['status']])<br><span class="badge {{ ['similar' => 'badge-warning', 'price' => 'badge-info'][$row['status']] ?? '' }}">{{ $statusLabels[$row['status']] }}</span>@endif
                                    @if ($row['similar'])<span class="small muted"> {{ $row['similar'] }}</span>@endif
                                </td>
                                <td class="num"><x-money-amount :value="$row['amount']" signed /></td>
                                <td>
                                    @unless ($row['status'] === 'known')
                                        <select name="category[{{ $i }}]" aria-label="Catégorie">
                                            <option value="">—</option>
                                            @foreach ($categoryOptions->where('type', $row['amount'] > 0 ? 'income' : 'expense') as $category)
                                                <option value="{{ $category->id }}" @selected($row['category_id'] === $category->id)>{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit"><x-icon name="check" /> Importer les lignes cochées</button>
            <a class="btn btn-secondary" href="{{ route('import.create') }}">Autre fichier</a>
        </div>
    </form>
@endsection
