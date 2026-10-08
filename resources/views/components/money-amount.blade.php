@props(['value', 'signed' => false])
@php
    // Montant de l'app Argent : flouté en « mode discret », vert / rouge avec un signe si $signed.
    $value = (int) $value;
    $tone = $signed ? ($value > 0 ? 'm-pos' : ($value < 0 ? 'm-neg' : '')) : '';
@endphp
<span {{ $attributes->merge(['class' => trim('m-amt amount '.$tone)]) }}>{{ $signed && $value > 0 ? '+' : '' }}{{ \App\Support\Money::format($value) }}</span>
