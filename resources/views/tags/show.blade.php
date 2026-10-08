@extends('layouts.app', ['title' => $tag->name])

@php
    $net = $stats['income'] - $stats['expense'];
    $percent = $tag->budget ? (int) round($stats['expense'] * 100 / max(1, $tag->budget)) : null;
@endphp

@section('content')
    @include('_nav')

    <p class="small"><a href="{{ route('tags.index') }}"><x-icon name="chevron-left" class="icon icon-inline" /> Chantiers et projets</a></p>

    <div class="page-head">
        <div>
            <h1><span class="tag-chip tag-chip-lg" style="background:{{ $tag->color }}"><x-icon name="tag" class="icon icon-inline" /> {{ $tag->name }}</span></h1>
            @if ($stats['first_on'])
                <p>Du {{ \Illuminate\Support\Carbon::parse($stats['first_on'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($stats['last_on'])->format('d/m/Y') }} · {{ $stats['count'] }} mouvement{{ $stats['count'] > 1 ? 's' : '' }}{{ $tag->archived_at ? ' · terminé' : '' }}</p>
            @endif
            @if ($tag->notes)<p class="small muted" style="white-space:pre-line">{{ $tag->notes }}</p>@endif
        </div>
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">Dépensé</span><span class="value m-neg"><x-money-amount :value="$stats['expense']" /></span></div>
        <div class="card kpi"><span class="label">Encaissé</span><span class="value m-pos"><x-money-amount :value="$stats['income']" /></span></div>
        <div class="card kpi"><span class="label">{{ $stats['income'] ? 'Marge' : 'Coût total' }}</span><span class="value"><x-money-amount :value="$stats['income'] ? $net : $stats['expense']" :signed="(bool) $stats['income']" /></span>
            @if ($stats['income'] > 0)<span class="delta">{{ (int) round($net * 100 / $stats['income']) }} % de ce qui est encaissé</span>@endif
        </div>
    </div>

    @if ($tag->budget)
        <div class="card">
            <div class="goal-head"><strong>Budget prévu</strong><span class="small"><x-money-amount :value="$stats['expense']" /> / <x-money-amount :value="$tag->budget" /> · {{ $percent }} %</span></div>
            <div class="progress is-{{ $percent > 100 ? 'danger' : ($percent >= 85 ? 'warning' : 'success') }}"><span style="width:{{ min(100, $percent) }}%"></span></div>
            <p class="small muted" style="margin:0">{{ $percent > 100 ? 'Dépassé de '.\App\Support\Money::plain($stats['expense'] - $tag->budget).'.' : 'Encore '.\App\Support\Money::plain($tag->budget - $stats['expense']).' avant le budget.' }}</p>
        </div>
    @endif

    @if ($categories->isNotEmpty())
        <div class="card">
            <h2>Où part l'argent</h2>
            <ul class="cat-list">
                @foreach ($categories as $item)
                    <li>
                        <span class="swatch-dot" style="background:{{ $item['color'] }}"></span>
                        <span>{{ $item['name'] }}</span>
                        <strong><x-money-amount :value="$item['amount']" /></strong>
                        <span class="cat-bar" aria-hidden="true"><span style="width:{{ max(2, (int) round($item['amount'] * 100 / max(1, $categories->first()['amount']))) }}%;background:{{ $item['color'] }}"></span></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Mouvements</h2><a class="small" href="{{ route('transactions.index', ['projet' => $tag->id, 'periode' => 'tout']) }}">Dans Mouvements</a></div>
        @if ($transactions->isEmpty())
            <p class="muted" style="margin:0">Aucun mouvement pour l'instant. Écrivez « {{ $tag->name }} » dans le champ « Chantier / projet » d'une dépense ou d'un paiement (aussi ceux venus de l'app de devis).</p>
        @else
            <ul class="stat-list">
                @foreach ($transactions as $t)
                    <li>
                        <a href="{{ route('transactions.edit', $t) }}" class="cal-line"><span class="swatch-dot" style="background:{{ $t->category?->color ?? '#B0BEC5' }}"></span>{{ $t->occurred_on->format('d/m') }} · {{ $t->label }} <span class="small muted">· {{ $t->category?->name ?? 'Sans catégorie' }}</span></a>
                        <strong><x-money-amount :value="$t->amount" :signed="! $t->isTransfer()" /></strong>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <details class="card" @if ($errors->any()) open @endif>
        <summary><strong>Modifier</strong></summary>
        <form method="POST" action="{{ route('tags.update', $tag) }}" style="margin-top:1rem">
            @csrf
            @method('PUT')
            <div class="form-grid cols-2">
                <x-field name="name" label="Nom" :value="$tag->name" required maxlength="60" />
                <x-field name="budget" label="Budget prévu (€)" inputmode="decimal" :value="$tag->budget ? \App\Support\Money::format($tag->budget, false) : ''" placeholder="facultatif" />
                <x-field name="tag_notes" label="Note" :value="$tag->notes" maxlength="500" class="span-2" />
            </div>
            <div class="color-picks" role="radiogroup" aria-label="Couleur">
                @foreach (\App\Models\MoneyTag::COLORS as $color)
                    <label class="color-pick" style="--c: {{ $color }}"><input type="radio" name="color" value="{{ $color }}" @checked($tag->color === $color)><span class="visually-hidden">{{ $color }}</span></label>
                @endforeach
            </div>
            <label class="check" style="margin-top:.5rem"><input type="checkbox" name="archived" value="1" @checked($tag->archived_at)> <span>Chantier ou projet terminé</span></label>
            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
        </form>
        <form method="POST" action="{{ route('tags.destroy', $tag) }}" data-confirm="Supprimer l'étiquette « {{ $tag->name }} » ? Les mouvements restent." style="margin-top:.5rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger-outline" type="submit">Supprimer</button>
        </form>
    </details>
@endsection
