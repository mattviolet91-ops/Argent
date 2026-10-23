@extends('layouts.app', ['title' => 'Aperçu du relevé'])

@php
    $statusLabels = ['new' => null, 'known' => 'Déjà importée', 'similar' => 'Déjà notée ?', 'price' => 'Nouveau prix'];
    $selected = collect($rows)->whereIn('status', ['new', 'price']);
    $expenses = $categoryOptions->where('type', 'expense');
    $incomes = $categoryOptions->where('type', 'income');
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Aperçu : {{ count($rows) }} opération{{ count($rows) > 1 ? 's' : '' }}</h1>
            <p>Compte « {{ $account->name }} »{{ $account->mixed ? ' (mixte)' : '' }}. Vérifiez, choisissez la catégorie, <strong>perso ou pro</strong> et, si besoin, le <strong>chantier</strong> de chaque opération, puis importez les lignes cochées.</p>
        </div>
    </div>

    @if ($unread)
        <div class="alert alert-warning">Rien lu dans : {{ implode(', ', $unread) }}. Les autres fichiers sont bien là ; pour celui-ci, réessayez seul pour voir le détail.</div>
    @endif
    @if ($guessed)
        <div class="alert alert-info">Relevé PDF : le sens de chaque opération (sortie <strong>−</strong> ou entrée <strong>+</strong>) a été deviné. Vérifiez les montants ; « Inverser » corrige une ligne.</div>
    @endif

    <div class="chips" style="margin-bottom:1rem">
        <span class="chip">{{ $counts['new'] ?? 0 }} nouvelle(s)</span>
        @if ($counts['similar'] ?? 0)<span class="chip">{{ $counts['similar'] }} ressemblant à un mouvement déjà noté (décochées)</span>@endif
        @if ($counts['known'] ?? 0)<span class="chip">{{ $counts['known'] }} déjà importée(s)</span>@endif
        @if ($counts['price'] ?? 0)<span class="chip">{{ $counts['price'] }} abonnement(s) à un nouveau prix</span>@endif
    </div>

    <form method="POST" action="{{ route('import.store') }}" data-busy="Import…" data-import-form>
        @csrf
        <div class="card import-tools">
            <label class="check"><input type="checkbox" data-check-all checked> <span>Tout cocher</span></label>
            <span class="import-bulk">Tout en
                <button class="btn btn-sm btn-secondary" type="button" data-scope-all="perso">Perso</button>
                <button class="btn btn-sm btn-secondary" type="button" data-scope-all="pro">Pro</button>
            </span>
            <span class="small muted">Entrées : <x-money-amount :value="$selected->where('amount', '>', 0)->sum('amount')" /> · Sorties : <x-money-amount :value="-$selected->where('amount', '<', 0)->sum('amount')" /></span>
            <div class="import-tag-bulk">
                <label for="bulk-tag">Chantier</label>
                <input id="bulk-tag" type="text" list="import-tag-list" maxlength="60" autocomplete="off" placeholder="ex. Dupont" data-tag-bulk>
                <button class="btn btn-sm btn-secondary" type="button" data-tag-apply><x-icon name="tag" /> Sur toutes les sorties pro cochées</button>
                <span class="small muted" data-tag-status role="status"></span>
            </div>
            <datalist id="import-tag-list">@foreach ($tagNames as $tagName)<option value="{{ $tagName }}"></option>@endforeach</datalist>
        </div>

        <ul class="list import-list">
            @foreach ($rows as $i => $row)
                @php
                    $known = $row['status'] === 'known';
                    $type = $row['amount'] > 0 ? 'income' : 'expense';
                @endphp
                <li class="import-row {{ $known ? 'is-known' : '' }}" data-import-row>
                    <label class="import-check"><input type="checkbox" name="import[{{ $i }}]" value="1" aria-label="Importer cette ligne" @checked(in_array($row['status'], ['new', 'price'], true)) @disabled($known) @unless ($known) data-check-item @endunless></label>
                    <div class="import-main">
                        <div class="import-top">
                            <span class="import-label"><span class="small muted">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</span> {{ $row['label'] }}</span>
                            <strong class="import-amount m-amt {{ $row['amount'] < 0 ? 'm-neg' : 'm-pos' }}" data-amount="{{ $row['amount'] }}">{{ ($row['amount'] > 0 ? '+' : '').\App\Support\Money::format($row['amount']) }}</strong>
                        </div>
                        @if ($statusLabels[$row['status']] || $row['similar'])
                            <div class="small">
                                @if ($statusLabels[$row['status']])<span class="badge {{ ['similar' => 'badge-warning', 'price' => 'badge-info'][$row['status']] ?? '' }}">{{ $statusLabels[$row['status']] }}</span>@endif
                                @if ($row['similar'])<span class="muted"> {{ $row['similar'] }}</span>@endif
                            </div>
                        @endif
                        @unless ($known)
                            <div class="import-controls">
                                <select name="category[{{ $i }}]" aria-label="Catégorie">
                                    <option value="">— Catégorie —</option>
                                    @if ($row['guessed'])
                                        <optgroup label="Dépenses">@foreach ($expenses as $category)<option value="{{ $category->id }}" @selected($row['category_id'] === $category->id)>{{ $category->name }}</option>@endforeach</optgroup>
                                        <optgroup label="Entrées">@foreach ($incomes as $category)<option value="{{ $category->id }}" @selected($row['category_id'] === $category->id)>{{ $category->name }}</option>@endforeach</optgroup>
                                    @else
                                        @foreach ($type === 'income' ? $incomes : $expenses as $category)
                                            <option value="{{ $category->id }}" @selected($row['category_id'] === $category->id)>{{ $category->name }}</option>
                                        @endforeach
                                    @endif
                                </select>
                                <div class="scope-switch" role="radiogroup" aria-label="Perso ou pro">
                                    @foreach (['perso' => 'Perso', 'pro' => 'Pro'] as $key => $label)
                                        <label><input type="radio" name="scope[{{ $i }}]" value="{{ $key }}" @checked($row['scope'] === $key) data-scope-choice> {{ $label }}</label>
                                    @endforeach
                                </div>
                                @if ($account->mixed && $row['scope_hint'] === 'account')<span class="badge badge-warning">à vérifier</span>@endif
                                <input class="import-tag" type="text" name="tag[{{ $i }}]" list="import-tag-list" maxlength="300" autocomplete="off" placeholder="Chantier" aria-label="Chantier ou projet (facultatif)" data-import-tag>
                                @if ($row['guessed'])
                                    <label class="check small import-flip"><input type="checkbox" name="flip[{{ $i }}]" value="1" data-flip> <span>Inverser</span></label>
                                @endif
                            </div>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="form-actions">
            <button class="btn" type="submit"><x-icon name="check" /> Importer les lignes cochées</button>
            <a class="btn btn-secondary" href="{{ route('import.create') }}">Autre fichier</a>
        </div>
        <p class="money-note">Vos choix perso / pro et vos catégories sont retenus : la prochaine fois, ils seront proposés tout seuls.</p>
    </form>
@endsection
