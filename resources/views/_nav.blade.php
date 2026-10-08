{{-- Onglets de l'app (le menu complet est dans le gabarit). --}}
@php
    $tabs = [
        ['dashboard', 'Résumé', 'dashboard'],
        ['transactions.index', 'Mouvements', 'transactions.*'],
        ['accounts.index', 'Comptes', 'accounts.*'],
        ['categories.index', 'Budgets', 'categories.*'],
        ['goals.index', 'Objectifs', 'goals.*'],
        ['recurrings.index', 'Fixes', 'recurrings.*'],
        ['reports.index', 'Bilans', 'reports.*'],
    ];
@endphp
<nav class="tabs money-tabs" aria-label="Pages">
    @foreach ($tabs as [$route, $label, $pattern])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'is-active' : '' }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
