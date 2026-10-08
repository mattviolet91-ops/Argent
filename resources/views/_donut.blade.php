{{-- Camembert des dépenses par catégorie. $items : Collection<{name, color, amount}>, $total : int. --}}
@php
    $top = $items->take(6);
    $rest = (int) $items->slice(6)->sum('amount');
    if ($rest > 0) { $top = $top->push(['id' => null, 'name' => 'Autres', 'color' => '#B0BEC5', 'amount' => $rest]); }
    $sum = max(1, (int) $top->sum('amount'));
    $offset = 25;
@endphp
<svg class="donut" viewBox="0 0 42 42" role="img" aria-label="Répartition des dépenses">
    <circle cx="21" cy="21" r="15.915" fill="none" stroke="var(--surface-2)" stroke-width="6" />
    @foreach ($top as $item)
        @php $part = $item['amount'] * 100 / $sum; @endphp
        <circle cx="21" cy="21" r="15.915" fill="none" stroke="{{ $item['color'] }}" stroke-width="6"
            stroke-dasharray="{{ round($part, 3) }} {{ round(100 - $part, 3) }}" stroke-dashoffset="{{ round($offset, 3) }}"><title>{{ $item['name'] }} : {{ \App\Support\Money::plain($item['amount']) }} ({{ (int) round($part) }} %)</title></circle>
        @php $offset -= $part; @endphp
    @endforeach
    <text class="donut-total m-amt" x="21" y="21.5" text-anchor="middle">{{ number_format($total / 100, 0, ',', ' ') }} €</text>
    <text class="donut-label" x="21" y="26" text-anchor="middle">dépensés</text>
</svg>
