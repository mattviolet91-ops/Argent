{{--
    Courbe(s) dans le temps. $labels : list<string> (abscisse, une par point), $ticks : list<int> (index des repères à écrire),
    $series : list<{label, class, values: list<int>}> (centimes), $title : texte pour les lecteurs d'écran.
    Survol / toucher : info-bulle par point (js/charts.js), et <title> en secours.
--}}
@php
    $width = 360; $left = 44; $right = 8; $top = 10; $plot = 140; $base = $top + $plot;
    $all = collect($series)->flatMap(fn ($s) => $s['values']);
    $min = min(0, (int) $all->min());
    $max = max(1, (int) $all->max());
    $pad = max(1, (int) round(($max - $min) * .08));
    [$lo, $hi] = [$min < 0 ? $min - $pad : 0, $max + $pad];
    $count = max(2, count($labels));
    $x = fn (int $i) => round($left + ($width - $left - $right) * $i / ($count - 1), 1);
    $y = fn (int $v) => round($base - ($v - $lo) * $plot / max(1, $hi - $lo), 1);
    $short = function (int $cents) {
        $euros = $cents / 100;
        return abs($euros) >= 10000 ? number_format($euros / 1000, abs($euros) % 1000 ? 1 : 0, ',', ' ').' k€' : number_format($euros, 0, ',', ' ').' €';
    };
    // Repères ronds (1, 2, 2,5 ou 5 × 10ⁿ) ; 0 toujours marqué.
    $raw = max(1, ($hi - $lo) / 3);
    $magnitude = 10 ** floor(log10($raw));
    $step = (int) (collect([1, 2, 2.5, 5, 10])->map(fn ($f) => $f * $magnitude)->first(fn ($c) => $c >= $raw) ?? $raw);
    $grid = collect(range((int) (ceil($lo / $step) * $step), (int) $hi, $step))->push(0)->unique()->sort()->values();
    // Repères de l'abscisse : pas deux trop proches (le dernier est gardé).
    $ticks = collect($ticks)->unique()->sort()->values();
    $gap = max(1, ($count - 1) / 6);
    $ticks = $ticks->filter(fn ($t, $k) => $t === $ticks->last() || $ticks->last() - $t >= $gap)->values()->all();
@endphp
<div class="line-chart" data-line-chart>
    <svg class="money-chart" viewBox="0 0 {{ $width }} {{ $base + 22 }}" role="img" aria-label="{{ $title }}">
        @foreach ($grid as $value)
            <line class="{{ $value === 0 && $lo < 0 ? 'zero' : 'grid' }}" x1="{{ $left }}" y1="{{ $y($value) }}" x2="{{ $width - $right }}" y2="{{ $y($value) }}" />
            <text class="tick" x="{{ $left - 4 }}" y="{{ $y($value) + 3 }}" text-anchor="end">{{ $short($value) }}</text>
        @endforeach
        @foreach ($ticks as $i)
            <text class="tick" x="{{ $x($i) }}" y="{{ $base + 15 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === count($labels) - 1 ? 'end' : 'middle') }}">{{ $labels[$i] }}</text>
        @endforeach
        @foreach ($series as $s)
            <polyline class="line {{ $s['class'] }}" points="{{ collect($s['values'])->map(fn ($v, $i) => $x($i).','.$y($v))->implode(' ') }}" />
            @php $lastIndex = count($s['values']) - 1; @endphp
            <circle class="dot {{ $s['class'] }}" cx="{{ $x($lastIndex) }}" cy="{{ $y($s['values'][$lastIndex]) }}" r="4" />
        @endforeach
        <line class="crosshair" x1="0" y1="{{ $top }}" x2="0" y2="{{ $base }}" hidden />
        @foreach ($labels as $i => $label)
            @php
                $tip = $label.' — '.collect($series)->map(fn ($s) => (count($series) > 1 ? $s['label'].' : ' : '').\App\Support\Money::plain($s['values'][$i]))->implode(' · ');
                $half = ($width - $left - $right) / ($count - 1) / 2;
            @endphp
            <rect class="hit" x="{{ max(0, $x($i) - $half) }}" y="0" width="{{ round($half * 2, 1) }}" height="{{ $base }}" data-tip="{{ $tip }}" data-x="{{ $x($i) }}"><title>{{ $tip }}</title></rect>
        @endforeach
    </svg>
    <div class="chart-tip m-amt" role="status" hidden></div>
</div>
