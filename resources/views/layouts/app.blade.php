@extends('layouts.base')

{{-- Gabarit des pages de l'app (après connexion et code Argent). --}}
@php
    $nav = [
        ['dashboard', 'Résumé', 'home', 'dashboard'],
        ['transactions.index', 'Mouvements', 'wallet', 'transactions.*'],
        ['accounts.index', 'Comptes', 'piggy', 'accounts.*'],
        ['categories.index', 'Budgets', 'chart', 'categories.*'],
        ['goals.index', 'Objectifs', 'target', 'goals.*'],
        ['recurrings.index', 'Fixes', 'repeat', 'recurrings.*'],
        ['calendar', 'Calendrier', 'calendar', 'calendar'],
        ['trends', 'Tendances', 'trend', 'trends'],
        ['purchases.index', 'Garanties', 'shield', 'purchases.*'],
        ['loans.index', 'Qui me doit quoi', 'users', 'loans.*'],
        ['reports.index', 'Bilans', 'book', 'reports.*'],
        ['import.create', 'Importer un relevé', 'upload', 'import.*'],
        ['settings', 'Réglages', 'settings', 'settings'],
    ];
    $unlocked = request()->routeIs(...array_column($nav, 3));
@endphp

@section('body')
<div class="app" @if ($unlocked) data-money-root data-lock-seconds="{{ app(\App\Services\MoneyLockService::class)->lockMinutes() * 60 }}" data-lock-url="{{ route('unlock') }}" data-lock-post="{{ route('lock') }}" data-lock-on-leave="{{ app(\App\Services\MoneyLockService::class)->locksOnLeave() ? '1' : '0' }}" @endif>
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard') }}">
            <img class="brand-img-icon" src="{{ asset('icons/icon-192.png') }}" alt="">
            <span class="brand-name">Argent</span>
        </a>
        <span class="spacer"></span>
        @if ($unlocked)
            <button class="icon-btn" type="button" data-money-discreet title="Mode discret : flouter les montants" aria-pressed="false"><x-icon name="eye-off" /><span class="visually-hidden">Mode discret</span></button>
        @endif
        <button class="icon-btn" type="button" data-theme-toggle title="Mode clair / sombre">
            <x-icon name="moon" class="icon theme-moon" /><x-icon name="sun" class="icon theme-sun" /><span class="visually-hidden">Mode clair / sombre</span>
        </button>
        @if ($unlocked)
            <form method="POST" action="{{ route('lock') }}">
                @csrf
                <button class="icon-btn" type="submit" title="Verrouiller"><x-icon name="lock" /><span class="visually-hidden">Verrouiller</span></button>
            </form>
        @endif
    </header>

    <div class="layout">
        @if ($unlocked)
            <nav class="sidebar" aria-label="Navigation principale">
                <button class="btn" type="button" data-open-sheet="money-add"><x-icon name="plus" /> Ajouter</button>
                @foreach ($nav as [$route, $label, $icon, $pattern])
                    <a class="nav-link {{ request()->routeIs($pattern) ? 'is-active' : '' }}" href="{{ route($route) }}" @if (request()->routeIs($pattern)) aria-current="page" @endif><x-icon :name="$icon" /> {{ $label }}</a>
                @endforeach
                <span class="nav-sep"></span>
                <form method="POST" action="{{ route('lock') }}">@csrf<button class="nav-link" type="submit"><x-icon name="lock" /> Verrouiller</button></form>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link" type="submit"><x-icon name="logout" /> Se déconnecter</button></form>
            </nav>
        @endif

        <main class="main">
            <div class="container">
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any() && ! $errors->hasAny(['type', 'amount', 'account_id', 'to_account_id', 'category_id', 'label', 'occurred_on', 'notes']))
                    <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>

    @if ($unlocked)
        <nav class="bottom-nav" aria-label="Navigation">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'is-active' : '' }}"><x-icon name="home" /> Résumé</a>
            <a href="{{ route('transactions.index') }}" class="{{ request()->routeIs('transactions.*') ? 'is-active' : '' }}"><x-icon name="wallet" /> Mouvements</a>
            <button type="button" class="fab" data-open-sheet="money-add"><span class="fab-circle"><x-icon name="plus" /></span> Ajouter</button>
            <a href="{{ route('goals.index') }}" class="{{ request()->routeIs('goals.*') ? 'is-active' : '' }}"><x-icon name="target" /> Objectifs</a>
            <button type="button" data-open-sheet="sheet-more" class="{{ request()->routeIs('accounts.*', 'categories.*', 'recurrings.*', 'calendar', 'trends', 'purchases.*', 'loans.*', 'reports.*', 'import.*', 'settings') ? 'is-active' : '' }}"><x-icon name="menu" /> Plus</button>
        </nav>

        <dialog class="sheet" id="sheet-more" aria-labelledby="sheet-more-title">
            <div class="card-head">
                <h2 id="sheet-more-title">Menu</h2>
                <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
            </div>
            <div class="sheet-grid">
                @foreach (array_slice($nav, 2) as [$route, $label, $icon])
                    <a class="sheet-item" href="{{ route($route) }}"><x-icon :name="$icon" /> {{ $label }}</a>
                @endforeach
            </div>
            <div class="sheet-footer">
                <form method="POST" action="{{ route('lock') }}">@csrf<button class="btn btn-secondary" type="submit"><x-icon name="lock" /> Verrouiller</button></form>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-secondary" type="submit"><x-icon name="logout" /> Se déconnecter</button></form>
            </div>
        </dialog>

        @include('_quick-add', ['accountOptions' => $quickAccounts, 'categoryOptions' => $quickCategories, 'scope' => session('argent.scope', 'all'), 'defaultAccount' => request()->integer('compte') ?: null])
        <script src="{{ asset('js/money.js') }}?v={{ filemtime(public_path('js/money.js')) }}" defer></script>
    @endif
</div>
@endsection
