@extends('layouts.app', ['title' => 'Garanties et factures'])

@php
    $prefill = $fromTransaction ? [
        'name' => $fromTransaction->label,
        'purchased_on' => $fromTransaction->occurred_on->toDateString(),
        'amount' => \App\Support\Money::format(abs($fromTransaction->amount), false),
        'transaction_id' => $fromTransaction->id,
    ] : [];
    $formOpen = $errors->any() || $fromTransaction || ($covered->isEmpty() && $others->isEmpty());
@endphp

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Garanties et factures</h1>
            <p>Gardez la facture de vos achats (photo ou PDF) avec leur date de fin de garantie : vous êtes prévenu un mois avant qu'elle se termine.</p>
        </div>
    </div>

    @if ($covered->isNotEmpty() || $others->isNotEmpty())
        <div class="grid money-mini">
            <div class="card kpi"><span class="label">Sous garantie</span><span class="value">{{ $covered->count() }}</span></div>
            <div class="card kpi"><span class="label">Se terminent dans le mois</span><span class="value {{ $soon ? 'm-neg' : '' }}">{{ $soon }}</span></div>
            <div class="card kpi"><span class="label">Factures gardées</span><span class="value">{{ $covered->merge($others)->filter->hasFile()->count() }}</span></div>
        </div>
    @endif

    @if ($covered->isNotEmpty())
        <h2 class="section-title">Sous garantie</h2>
        <ul class="list">
            @foreach ($covered as $purchase)
                @include('purchases._item', ['purchase' => $purchase])
            @endforeach
        </ul>
    @endif

    <details class="card" id="ajouter" @if ($formOpen) open @endif>
        <summary><strong>+ Ajouter un achat</strong></summary>
        @if ($fromTransaction)
            <p class="small muted">Depuis le mouvement « {{ $fromTransaction->label }} » du {{ $fromTransaction->occurred_on->format('d/m/Y') }}.</p>
        @endif
        <form method="POST" action="{{ route('purchases.store') }}" enctype="multipart/form-data" data-money-switch="warranty" data-busy="Envoi de la facture…" style="margin-top:1rem">
            @csrf
            @include('purchases._fields', ['purchase' => null, 'prefill' => $prefill])
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="receipt" /> Enregistrer</button></div>
        </form>
    </details>

    @if ($others->isNotEmpty())
        <details class="card">
            <summary><strong>Garantie terminée ou sans garantie ({{ $others->count() }})</strong></summary>
            <ul class="list" style="margin-top:.75rem">
                @foreach ($others as $purchase)
                    @include('purchases._item', ['purchase' => $purchase])
                @endforeach
            </ul>
        </details>
    @endif
    <script src="{{ asset('js/purchases.js') }}?v={{ filemtime(public_path('js/purchases.js')) }}" defer></script>
@endsection
