{{-- Graphique : entrées (vert) et sorties (rouge) par mois. $series : list<{month, income, expense, net}>. --}}
@php
    $count = max(1, count($series));
    $width = 360; $top = 6; $plot = 120; $base = $top + $plot;
    $max = max(1, collect($series)->max(fn ($m) => max($m['income'], $m['expense'])));
    $slot = $width / $count;
    $bar = min(14, $slot * .34);
    $fmt = fn (int $cents) => \App\Support\Money::plain($cents);
@endphp
<svg class="money-chart" viewBox="0 0 {{ $width }} {{ $base + 22 }}" role="img" aria-label="Entrées et sorties par mois">
    <line class="axis" x1="0" y1="{{ $base }}" x2="{{ $width }}" y2="{{ $base }}" />
    @foreach ($series as $i => $point)
        @php
            $center = $slot * $i + $slot / 2;
            $hIn = $point['income'] ? max(2, $point['income'] * $plot / $max) : 0;
            $hOut = $point['expense'] ? max(2, $point['expense'] * $plot / $max) : 0;
            $label = ucfirst($point['month']->locale('fr')->isoFormat('MMMM YYYY'));
        @endphp
        <g>
            <title>{{ $label }} : {{ $fmt($point['income']) }} entrés, {{ $fmt($point['expense']) }} sortis, résultat {{ $point['net'] >= 0 ? '+' : '' }}{{ $fmt($point['net']) }}</title>
            <rect class="bar-in" x="{{ round($center - $bar - 1, 1) }}" y="{{ round($base - $hIn, 1) }}" width="{{ round($bar, 1) }}" height="{{ round($hIn, 1) }}" rx="3" />
            <rect class="bar-out" x="{{ round($center + 1, 1) }}" y="{{ round($base - $hOut, 1) }}" width="{{ round($bar, 1) }}" height="{{ round($hOut, 1) }}" rx="3" />
            <text class="tick" x="{{ round($center, 1) }}" y="{{ $base + 15 }}" text-anchor="middle">{{ rtrim($point['month']->locale('fr')->isoFormat($count > 6 ? 'MMM' : 'MMM YY'), '.') }}</text>
        </g>
    @endforeach
</svg>
<div class="money-legend"><span><span class="swatch-dot" style="background:var(--success)"></span>Entrées</span><span><span class="swatch-dot" style="background:var(--danger)"></span>Sorties</span><span>Touchez un mois pour le détail.</span></div>
