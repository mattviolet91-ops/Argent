{{-- Onglets : les pages de la section de la page affichée (le menu complet est dans le gabarit). --}}
<nav class="tabs money-tabs" aria-label="Pages">
    @foreach (\App\Support\Nav::current() as [$route, $label, $icon, $pattern])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'is-active' : '' }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
