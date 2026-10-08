@php
    $status = $purchase->status();
    $days = $purchase->daysLeft();
@endphp
<li>
    <a class="list-item" href="{{ route('purchases.show', $purchase) }}">
        <span class="tx-icon" style="background:{{ ['covered' => 'var(--success)', 'soon' => 'var(--warning)', 'ended' => '#94A3B8', 'none' => '#94A3B8'][$status] }}" aria-hidden="true"><x-icon name="{{ $status === 'none' ? 'receipt' : 'shield' }}" /></span>
        <span class="list-main">
            <strong>{{ $purchase->name }}</strong>
            <span class="muted small purchase-meta">
                @switch($status)
                    @case('covered') Garanti jusqu'au {{ $purchase->warranty_until->format('d/m/Y') }} @break
                    @case('soon') <span class="badge badge-warning">{{ $days === 0 ? 'se termine aujourd\'hui' : 'plus que '.$days.' jour'.($days > 1 ? 's' : '') }}</span> fin le {{ $purchase->warranty_until->format('d/m/Y') }} @break
                    @case('ended') Garantie terminée le {{ $purchase->warranty_until->format('d/m/Y') }} @break
                    @default Acheté le {{ $purchase->purchased_on->format('d/m/Y') }}
                @endswitch
                {{ $purchase->shop ? '· '.$purchase->shop : '' }}
                @if ($purchase->hasFile()) · <x-icon name="file" class="icon icon-inline" /> facture @endif
            </span>
        </span>
        @if ($purchase->amount)<span class="list-meta"><strong><x-money-amount :value="$purchase->amount" /></strong></span>@endif
    </a>
</li>
