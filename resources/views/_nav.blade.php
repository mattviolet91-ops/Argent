{{-- Onglets de l'app (le menu complet est dans le gabarit). --}}
@php
    $tabs = [
        ['dashboard', 'Résumé', 'dashboard'],
        ['transactions.index', 'Mouvements', 'transactions.*'],
        ['calendar', 'Calendrier', 'calendar'],
        ['accounts.index', 'Comptes', 'accounts.*'],
        ['categories.index', 'Budgets', 'categories.*'],
        ['goals.index', 'Objectifs', 'goals.*'],
        ['recurrings.index', 'Fixes', 'recurrings.*'],
        ['trends', 'Tendances', 'trends'],
        ['reports.index', 'Bilans', 'reports.*'],
        ['purchases.index', 'Garanties', 'purchases.*'],
        ['loans.index', 'Qui me doit', 'loans.*'],
    ];
@endphp
<nav class="tabs money-tabs" aria-label="Pages">
    @foreach ($tabs as [$route, $label, $pattern])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'is-active' : '' }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
