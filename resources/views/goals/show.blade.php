@extends('layouts.app', ['title' => $goal->name])

@php
    use App\Support\Money;
    $p = $progress;
    $percent = $p['percent'];
    $left = max(0, $goal->target - $p['current']);
    $circumference = 2 * M_PI * 52;
    $dash = $circumference * min(100, $percent) / 100;
    $defaultSource = $sourceAccounts->firstWhere('kind', 'courant') ?? $sourceAccounts->first();
    $moveErrors = $errors->hasAny(['move_amount', 'other_account_id', 'occurred_on']);
@endphp

@section('content')
    @include('_nav')

    <div class="goal-hero card">
        <div class="goal-ring">
            <svg viewBox="0 0 120 120" role="img" aria-label="{{ $percent }} % de l'objectif">
                <circle cx="60" cy="60" r="52" class="ring-track" />
                <circle cx="60" cy="60" r="52" class="ring-fill {{ $percent >= 100 ? 'is-done' : '' }}" stroke-dasharray="{{ round($dash, 2) }} {{ round($circumference, 2) }}" transform="rotate(-90 60 60)" />
            </svg>
            <span class="ring-icon"><x-icon :name="$goal->iconName()" /></span>
            <span class="ring-percent">{{ $percent }} %</span>
        </div>
        <div class="goal-hero-text">
            <h1>{{ $goal->name }}</h1>
            <p class="goal-amounts"><strong><x-money-amount :value="$p['current']" /></strong> <span class="muted">sur <x-money-amount :value="$goal->target" /></span></p>
            @if ($percent >= 100)
                <p><span class="badge badge-success">Objectif atteint 🎉</span></p>
            @else
                <p class="muted" style="margin:0">Il manque <strong><x-money-amount :value="$left" /></strong>
                    @if ($goal->deadline) · échéance le {{ $goal->deadline->format('d/m/Y') }} ({{ $p['days_left'] ?? 0 }} jour{{ ($p['days_left'] ?? 0) > 1 ? 's' : '' }})@endif
                </p>
            @endif
            @if ($goal->account)
                <p class="small muted" style="margin:.25rem 0 0">Compte séparé : <a href="{{ route('accounts.show', $goal->account) }}">{{ $goal->account->name }}</a></p>
            @endif
        </div>
    </div>

    <div class="money-actions" style="margin:1rem 0">
        @if ($goal->account)
            <button class="btn" type="button" data-open-sheet="goal-deposit"><x-icon name="arrow-down" /> Déposer</button>
            <button class="btn btn-secondary" type="button" data-open-sheet="goal-withdraw"><x-icon name="arrow-up" /> Retirer</button>
        @else
            <form method="POST" action="{{ route('goals.contribute', $goal) }}" class="budget-form">
                @csrf
                <input type="text" name="contribution" inputmode="decimal" placeholder="ex. 50 ou -20" aria-label="Montant mis de côté">
                <button class="btn btn-sm" type="submit">Ajouter</button>
            </form>
            <form method="POST" action="{{ route('goals.account', $goal) }}" data-confirm="Créer un compte séparé pour cet objectif ? Ce qui est déjà mis de côté y sera reporté.">
                @csrf
                <button class="btn btn-sm btn-secondary" type="submit"><x-icon name="piggy" /> Passer sur un compte séparé</button>
            </form>
        @endif
    </div>
    @error('contribution')<p class="error">{{ $message }}</p>@enderror

    @if ($percent < 100)
        <div class="card">
            <h2>Le rythme</h2>
            <ul class="stat-list">
                @isset($p['needed_monthly'])
                    <li><span>À mettre de côté chaque mois pour l'échéance</span><strong><x-money-amount :value="$p['needed_monthly']" /></strong></li>
                @endisset
                @if (($p['monthly_auto'] ?? 0) > 0)
                    <li><span>Versements automatiques (par mois)</span><strong><x-money-amount :value="$p['monthly_auto']" /></strong></li>
                @endif
                @if (($p['planned'] ?? 0) !== 0)
                    <li><span>Dépôts déjà prévus</span><strong><x-money-amount :value="$p['planned']" /></strong></li>
                @endif
                @isset($p['projection'])
                    <li><span>À ce rythme, objectif atteint</span>
                        <strong class="{{ $p['on_track'] ? 'm-pos' : 'm-neg' }}">en {{ $p['projection']->locale('fr')->isoFormat('MMMM YYYY') }}{{ $goal->deadline ? ($p['on_track'] ? ' · dans les temps' : ' · en retard') : '' }}</strong></li>
                @endisset
                @if (! isset($p['needed_monthly']) && ! isset($p['projection']))
                    <li><span>{{ $goal->deadline ? 'Échéance passée' : 'Pas de date d\'échéance' }}</span><span class="small muted">Ajoutez une date ou un versement automatique pour voir le rythme.</span></li>
                @endif
            </ul>
        </div>
    @endif

    @if ($goal->account)
        <div class="card">
            <div class="card-head"><h2>Versements automatiques</h2></div>
            @if ($autos->isNotEmpty())
                <ul class="stat-list">
                    @foreach ($autos as $auto)
                        <li>
                            <span>{{ $auto->frequencyLabel() }} depuis « {{ $auto->account?->name }} » <span class="small muted">· prochain le {{ $auto->next_on->format('d/m/Y') }}{{ $auto->active ? '' : ' · en pause' }}</span></span>
                            <span class="money-actions">
                                <strong><x-money-amount :value="$auto->amount" /></strong>
                                <form method="POST" action="{{ route('recurrings.update', $auto) }}">@csrf @method('PUT')<input type="hidden" name="toggle" value="1"><button class="link-btn small" type="submit">{{ $auto->active ? 'Pause' : 'Reprendre' }}</button></form>
                                <form method="POST" action="{{ route('recurrings.destroy', $auto) }}" data-confirm="Arrêter ce versement automatique ?">@csrf @method('DELETE')<button class="link-btn small" type="submit">Arrêter</button></form>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="small muted" style="margin-top:0">Mettez de côté sans y penser : un virement automatique vers cet objectif, chaque mois par exemple.</p>
            @endif
            <details @if ($errors->hasAny(['auto_amount', 'from_account_id', 'frequency', 'next_on'])) open @endif>
                <summary class="small"><strong>+ Programmer un versement</strong></summary>
                <form method="POST" action="{{ route('goals.automatic', $goal) }}" class="form-grid cols-2" style="margin-top:.75rem">
                    @csrf
                    <x-field name="auto_amount" label="Montant (€)" required inputmode="decimal" :value="isset($p['needed_monthly']) ? Money::format($p['needed_monthly'], false) : ''" placeholder="ex. 200" />
                    <div class="field">
                        <label for="from_account_id">Depuis le compte</label>
                        <select id="from_account_id" name="from_account_id">
                            @foreach ($sourceAccounts as $account)
                                <option value="{{ $account->id }}" @selected((string) old('from_account_id', $defaultSource?->id) === (string) $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-select name="frequency" label="Fréquence" :options="\App\Models\MoneyRecurring::FREQUENCIES" value="mensuel" :placeholder="false" />
                    <x-field name="next_on" label="Premier versement le" type="date" :value="today()->toDateString()" required />
                    <div class="span-2"><button class="btn btn-sm" type="submit"><x-icon name="repeat" /> Programmer</button></div>
                </form>
            </details>
        </div>

        <div class="card">
            <div class="card-head"><h2>Dépôts et retraits</h2><a class="small" href="{{ route('transactions.index', ['compte' => $goal->account_id, 'periode' => 'tout']) }}">Tout voir</a></div>
            @if ($movements->isEmpty())
                <p class="muted" style="margin:0">Aucun dépôt pour l'instant. Touchez « Déposer » pour commencer.</p>
            @else
                <ul class="stat-list">
                    @foreach ($movements as $t)
                        <li>
                            <span>{{ $t->occurred_on->format('d/m/Y') }} · {{ $t->amount >= 0 ? 'Dépôt' : 'Retrait' }}
                                @if ($t->occurred_on->isFuture())<span class="badge badge-info">prévu</span>@endif
                                @if ($t->source === 'recurring')<span class="badge">auto</span>@endif
                                @if ($t->notes)<span class="small muted">· {{ $t->notes }}</span>@endif
                            </span>
                            <strong><x-money-amount :value="$t->amount" signed /></strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <details class="card" @if ($errors->hasAny(['name', 'target', 'deadline', 'icon'])) open @endif>
        <summary><strong>Modifier l'objectif</strong></summary>
        <form method="POST" action="{{ route('goals.update', $goal) }}" style="margin-top:1rem">
            @csrf
            @method('PUT')
            @include('goals._fields', ['goal' => $goal])
            <label class="check" style="margin-top:.5rem"><input type="checkbox" name="archived" value="1" @checked($goal->archived_at)> <span>Archiver (projet terminé ou abandonné)</span></label>
            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
        </form>
        <form method="POST" action="{{ route('goals.destroy', $goal) }}" data-confirm="Supprimer cet objectif ?{{ $goal->account ? ' Son compte et son argent resteront dans Comptes.' : '' }}" style="margin-top:.5rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger-outline" type="submit">Supprimer l'objectif</button>
        </form>
    </details>

    @if ($goal->account)
        @foreach (['deposit' => ['Déposer', 'Depuis le compte', 'goals.deposit'], 'withdraw' => ['Retirer', 'Vers le compte', 'goals.withdraw']] as $mode => [$title, $accountLabel, $route])
            <dialog class="sheet" id="goal-{{ $mode }}" aria-labelledby="goal-{{ $mode }}-title" @if ($moveErrors && old('mode') === $mode) data-open-on-load @endif>
                <div class="card-head">
                    <h2 id="goal-{{ $mode }}-title">{{ $title }} · {{ $goal->name }}</h2>
                    <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
                </div>
                <form method="POST" action="{{ route($route, $goal) }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="mode" value="{{ $mode }}">
                    <div class="field @error('move_amount') has-error @enderror">
                        <label for="{{ $mode }}-amount">Montant (€)</label>
                        <input id="{{ $mode }}-amount" class="amount-input" type="text" name="move_amount" inputmode="decimal" placeholder="0,00" value="{{ old('mode') === $mode ? old('move_amount') : '' }}" required>
                        @if (old('mode') === $mode) @error('move_amount')<span class="error">{{ $message }}</span>@enderror @endif
                    </div>
                    <div class="field">
                        <label for="{{ $mode }}-account">{{ $accountLabel }}</label>
                        <select id="{{ $mode }}-account" name="other_account_id">
                            @foreach ($sourceAccounts as $account)
                                <option value="{{ $account->id }}" @selected((string) $defaultSource?->id === (string) $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="{{ $mode }}-date">Date</label>
                        <input id="{{ $mode }}-date" type="date" name="occurred_on" value="{{ today()->toDateString() }}" required>
                        @if ($mode === 'deposit')<span class="hint">Une date à venir = dépôt prévu, compté ce jour-là.</span>@endif
                    </div>
                    <x-field name="notes" label="Note" placeholder="facultatif" maxlength="500" />
                    <button class="btn btn-block" type="submit">{{ $title }}</button>
                </form>
            </dialog>
        @endforeach
    @endif
@endsection
