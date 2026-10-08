@extends('layouts.app', ['title' => $purchase->name])

@php
    $status = $purchase->status();
    $days = $purchase->daysLeft();
@endphp

@section('content')
    @include('_nav')

    <p class="small"><a href="{{ route('purchases.index') }}"><x-icon name="chevron-left" class="icon icon-inline" /> Garanties et factures</a></p>

    <div class="card">
        <div class="card-head">
            <h1 style="margin:0">{{ $purchase->name }}</h1>
            @if ($purchase->amount)<strong class="purchase-price"><x-money-amount :value="$purchase->amount" /></strong>@endif
        </div>
        @switch($status)
            @case('covered')
                <div class="alert alert-success" style="margin:0 0 .75rem">Sous garantie jusqu'au <strong>{{ $purchase->warranty_until->format('d/m/Y') }}</strong> (encore {{ $days }} jours). Vous serez prévenu un mois avant.</div>
                @break
            @case('soon')
                <div class="alert alert-warning" style="margin:0 0 .75rem">La garantie se termine le <strong>{{ $purchase->warranty_until->format('d/m/Y') }}</strong> ({{ $days === 0 ? 'aujourd\'hui' : 'dans '.$days.' jour'.($days > 1 ? 's' : '') }}). Un souci avec cet achat ? C'est le moment de contacter le magasin.</div>
                @break
            @case('ended')
                <div class="alert alert-info" style="margin:0 0 .75rem">Garantie terminée le {{ $purchase->warranty_until->format('d/m/Y') }}.</div>
                @break
        @endswitch
        <ul class="stat-list">
            <li><span>Acheté le</span><strong>{{ $purchase->purchased_on->format('d/m/Y') }}</strong></li>
            @if ($purchase->shop)<li><span>Magasin</span><strong>{{ $purchase->shop }}</strong></li>@endif
            @if ($purchase->transaction)
                <li><span>Mouvement</span><a href="{{ route('transactions.edit', $purchase->transaction) }}">{{ $purchase->transaction->label }} · {{ $purchase->transaction->account?->name }}</a></li>
            @endif
            @if ($purchase->notes)<li><span>Note</span><span style="white-space:pre-line;text-align:right">{{ $purchase->notes }}</span></li>@endif
        </ul>
    </div>

    <div class="card">
        <div class="card-head"><h2>Facture</h2>
            @if ($purchase->hasFile())<span class="small muted">{{ number_format(($purchase->file_size ?? 0) / 1024, 0, ',', ' ') }} Ko</span>@endif
        </div>
        @if (! $purchase->hasFile())
            <p class="muted" style="margin:0">Pas encore de facture. Ajoutez une photo ou le PDF avec « Modifier » ci-dessous.</p>
        @else
            @if ($purchase->isImage() && ! in_array($purchase->file_mime, ['image/heic', 'image/heif'], true))
                <a href="{{ route('purchases.file', $purchase) }}" target="_blank" rel="noopener"><img class="purchase-photo" src="{{ route('purchases.file', $purchase) }}" alt="Facture : {{ $purchase->name }}"></a>
            @endif
            <div class="money-actions" style="margin-top:.75rem">
                <a class="btn btn-secondary btn-sm" href="{{ route('purchases.file', $purchase) }}" target="_blank" rel="noopener"><x-icon name="eye" /> Ouvrir</a>
                <a class="btn btn-secondary btn-sm" href="{{ route('purchases.file', ['purchase' => $purchase, 'telecharger' => 1]) }}" data-share-file><x-icon name="arrow-down" /> Enregistrer / partager</a>
            </div>
        @endif
    </div>

    <details class="card" @if ($errors->any()) open @endif>
        <summary><strong>Modifier</strong></summary>
        <form method="POST" action="{{ route('purchases.update', $purchase) }}" enctype="multipart/form-data" data-money-switch="warranty" data-busy="Enregistrement…" style="margin-top:1rem">
            @csrf
            @method('PUT')
            @include('purchases._fields', ['purchase' => $purchase])
            @if ($purchase->hasFile())
                <label class="check" style="margin-top:.5rem"><input type="checkbox" name="remove_file" value="1"> <span>Retirer la facture</span></label>
            @endif
            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
        </form>
        <form method="POST" action="{{ route('purchases.destroy', $purchase) }}" data-confirm="Supprimer cet achat et sa facture ?" style="margin-top:.5rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger-outline" type="submit"><x-icon name="trash" /> Supprimer</button>
        </form>
    </details>
@endsection
