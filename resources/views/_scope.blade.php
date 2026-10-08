{{-- Vue Tout / Perso / Pro (garde les autres filtres). --}}
<nav class="segmented segmented-3" aria-label="Vue">
    @foreach (\App\Services\MoneyStatsService::SCOPES as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['vue' => $key, 'page' => null]) }}" class="{{ $scope === $key ? 'is-active' : '' }}" @if ($scope === $key) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
</nav>
