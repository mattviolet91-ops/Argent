@extends('layouts.app', ['title' => $person->name])

@section('content')
    @include('_nav')

    <p class="small"><a href="{{ route('loans.index') }}"><x-icon name="chevron-left" class="icon icon-inline" /> Qui me doit quoi</a></p>

    <div class="card loan-hero">
        <span class="tx-icon loan-avatar loan-avatar-lg" aria-hidden="true">{{ mb_strtoupper(mb_substr($person->name, 0, 1)) }}</span>
        <div>
            <h1 style="margin:0">{{ $person->name }}</h1>
            <p class="muted small" style="margin:.125rem 0 .5rem">{{ $person->relationLabel() }}{{ $person->archived_at ? ' · archivé' : '' }}</p>
            @if ($balance > 0)
                <p class="loan-balance">Vous doit <strong class="m-pos"><x-money-amount :value="$balance" /></strong></p>
            @elseif ($balance < 0)
                <p class="loan-balance">Vous lui devez <strong class="m-neg"><x-money-amount :value="-$balance" /></strong></p>
            @else
                <p class="loan-balance"><span class="badge badge-success">Vous êtes quittes</span></p>
            @endif
            @if ($person->due_on && $balance > 0)
                <p class="small {{ $person->isOverdue() ? 'm-neg' : 'muted' }}" style="margin:.25rem 0 0">{{ $person->isOverdue() ? 'En retard : devait rembourser le ' : 'À rembourser avant le ' }}{{ $person->due_on->format('d/m/Y') }}</p>
            @endif
        </div>
    </div>

    <div class="money-actions" style="margin:1rem 0">
        <button class="btn" type="button" data-open-sheet="loan-add-rembourse"><x-icon name="arrow-down" /> {{ $balance < 0 ? 'Je l\'ai remboursé' : 'Remboursement reçu' }}</button>
        <button class="btn btn-secondary" type="button" data-open-sheet="loan-add-pret"><x-icon name="plus" /> Autre ligne</button>
    </div>

    <div class="card">
        <div class="card-head"><h2>Historique</h2></div>
        @if ($entries->isEmpty())
            <p class="muted" style="margin:0">Rien de noté.</p>
        @else
            <ul class="stat-list">
                @foreach ($entries as $entry)
                    <li>
                        <span>{{ $entry->occurred_on->format('d/m/Y') }} · {{ $entry->label() }}
                            @if ($entry->note)<span class="small muted">· {{ $entry->note }}</span>@endif
                            @if ($entry->transaction)<a class="small" href="{{ route('transactions.edit', $entry->transaction) }}">· {{ $entry->transaction->account?->name }}</a>@endif
                        </span>
                        <span class="loan-entry-actions">
                            <strong><x-money-amount :value="$entry->amount" signed /></strong>
                            <form method="POST" action="{{ route('loans.entry.destroy', $entry) }}" data-confirm="Supprimer cette ligne{{ $entry->transaction_id ? ' (et son mouvement sur le compte)' : '' }} ?">@csrf @method('DELETE')<button class="icon-btn" type="submit" title="Supprimer"><x-icon name="trash" /><span class="visually-hidden">Supprimer</span></button></form>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="small muted" style="margin-bottom:0">+ : ce qu'on vous doit augmente (prêt, avance). − : ça diminue (remboursement).</p>
        @endif
    </div>

    <details class="card" @if ($errors->hasAny(['name', 'relation', 'due_on', 'person_notes'])) open @endif>
        <summary><strong>Modifier</strong></summary>
        <form method="POST" action="{{ route('loans.update', $person) }}" style="margin-top:1rem">
            @csrf
            @method('PUT')
            <div class="form-grid cols-2">
                <x-field name="name" label="Nom" :value="$person->name" required maxlength="80" />
                <x-select name="relation" label="C'est" :options="\App\Models\MoneyPerson::RELATIONS" :value="$person->relation" :placeholder="false" />
                <x-field name="due_on" label="À rembourser avant le" type="date" :value="$person->due_on?->toDateString()" />
                <x-field name="person_notes" label="Note" :value="$person->notes" maxlength="500" />
            </div>
            <label class="check" style="margin-top:.5rem"><input type="checkbox" name="archived" value="1" @checked($person->archived_at)> <span>Archiver (ne plus l'afficher dans la liste)</span></label>
            <div class="form-actions"><button class="btn btn-sm" type="submit">Enregistrer</button></div>
        </form>
        <form method="POST" action="{{ route('loans.destroy', $person) }}" data-confirm="Supprimer {{ $person->name }} et tout son historique ? Les mouvements notés sur vos comptes restent." style="margin-top:.5rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger-outline" type="submit">Supprimer</button>
        </form>
    </details>

    @foreach (['rembourse' => $balance < 0 ? 'je_rembourse' : 'rembourse', 'pret' => 'pret'] as $sheet => $type)
        <dialog class="sheet" id="loan-add-{{ $sheet }}" aria-labelledby="loan-add-{{ $sheet }}-title" @if ($errors->hasAny(['loan_type', 'loan_amount', 'loan_on', 'loan_account', 'note']) && old('sheet') === $sheet) data-open-on-load @endif>
            <div class="card-head">
                <h2 id="loan-add-{{ $sheet }}-title">{{ $person->name }}</h2>
                <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
            </div>
            <form method="POST" action="{{ route('loans.entry', $person) }}">
                @csrf
                <input type="hidden" name="sheet" value="{{ $sheet }}">
                @include('loans._entry', ['type' => old('sheet') === $sheet ? null : $type, 'amount' => $sheet === 'rembourse' && $balance !== 0 ? \App\Support\Money::format(abs($balance), false) : '', 'suffix' => '-'.$sheet])
                <button class="btn btn-block" type="submit" style="margin-top:1rem">Enregistrer</button>
            </form>
        </dialog>
    @endforeach
@endsection
