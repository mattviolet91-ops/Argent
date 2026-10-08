{{-- Choix de la période (semaine, mois, année, dates libres). $periods : clé => libellé. --}}
<form method="GET" action="{{ url()->current() }}" class="period-bar">
    @foreach (request()->except(['periode', 'du', 'au', 'page']) as $key => $value)
        @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach
    <div class="chips" role="group" aria-label="Période">
        @foreach ($periods as $key => $label)
            <a class="chip {{ $period === $key ? 'is-active' : '' }}" href="{{ request()->fullUrlWithQuery(['periode' => $key, 'du' => null, 'au' => null, 'page' => null]) }}">{{ $label }}</a>
        @endforeach
        <details class="period-custom" @if ($period === 'perso') open @endif>
            <summary class="chip {{ $period === 'perso' ? 'is-active' : '' }}">Dates…</summary>
            <input type="hidden" name="periode" value="perso">
            <input type="date" name="du" value="{{ $from->toDateString() }}" aria-label="Du">
            <input type="date" name="au" value="{{ $to->toDateString() }}" aria-label="Au">
            <button class="btn btn-sm" type="submit">OK</button>
        </details>
    </div>
</form>
