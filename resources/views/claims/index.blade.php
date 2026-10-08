@extends('layouts.app', ['title' => 'Notes de frais'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Notes de frais</h1>
            <p>Une dépense pro payée avec votre carte perso : elle compte dans le pro (pas dans vos dépenses perso), et reste ici jusqu'à ce que la société vous rembourse.</p>
        </div>
    </div>

    <div class="grid money-mini">
        <div class="card kpi"><span class="label">À vous faire rembourser</span><span class="value {{ $pendingTotal ? 'm-neg' : '' }}"><x-money-amount :value="$pendingTotal" /></span>
            @if ($oldest)<span class="delta">la plus ancienne : {{ $oldest->format('d/m/Y') }}</span>@endif</div>
        <div class="card kpi"><span class="label">Remboursé en {{ today()->year }}</span><span class="value m-pos"><x-money-amount :value="$settledThisYear" /></span></div>
    </div>

    @if ($pending->isEmpty())
        <div class="card empty">
            <x-icon name="receipt" />
            <h2>Rien à vous faire rembourser</h2>
            <p>En ajoutant une dépense, cochez « Note de frais » quand vous avez payé un achat pro avec un compte perso.</p>
            <p><button class="btn" type="button" data-open-sheet="money-add"><x-icon name="plus" /> Ajouter une dépense</button></p>
        </div>
    @else
        <form method="POST" action="{{ route('claims.settle') }}" class="card">
            @csrf
            <div class="card-head"><h2>À rembourser</h2><label class="check small"><input type="checkbox" data-check-all checked> <span>Tout</span></label></div>
            <ul class="stat-list">
                @foreach ($pending as $t)
                    <li>
                        <label class="check claim-line"><input type="checkbox" name="ids[]" value="{{ $t->id }}" data-check-item checked>
                            <span>{{ $t->occurred_on->format('d/m/Y') }} · <a href="{{ route('transactions.edit', $t) }}">{{ $t->label }}</a>
                                <span class="small muted">· {{ $t->category?->name ?? 'Sans catégorie' }} · payé avec {{ $t->account?->name }}@if ($t->attachments_count) · <x-icon name="paperclip" class="icon icon-inline" />@endif</span></span>
                        </label>
                        <strong><x-money-amount :value="-$t->amount" /></strong>
                    </li>
                @endforeach
            </ul>
            @error('ids')<p class="error">{{ $message }}</p>@enderror
            <h3 style="margin-top:1rem">Remboursées</h3>
            <div class="form-grid cols-2">
                <x-field name="settled_on" label="Le" type="date" :value="today()->toDateString()" required />
                <div class="field">
                    <label for="from_account">Virement depuis <span class="muted small">(facultatif)</span></label>
                    <select id="from_account" name="from_account">
                        <option value="">— Pas de virement à noter —</option>
                        @foreach ($proAccounts as $account)<option value="{{ $account->id }}" @selected($loop->first)>{{ $account->name }}</option>@endforeach
                    </select>
                </div>
                <div class="field @error('to_account') has-error @enderror">
                    <label for="to_account">Vers</label>
                    <select id="to_account" name="to_account">
                        <option value="">—</option>
                        @foreach ($persoAccounts as $account)<option value="{{ $account->id }}" @selected($loop->first)>{{ $account->name }}</option>@endforeach
                    </select>
                    @error('to_account')<span class="error">{{ $message }}</span>@enderror
                </div>
            </div>
            <p class="small muted">Le virement est noté sur vos comptes (ni gagné, ni dépensé). Laissez « Pas de virement » s'il arrive déjà par un relevé importé, ou si vous avez été payé autrement.</p>
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="check" /> Marquer comme remboursées</button></div>
        </form>
    @endif

    @if ($settled->isNotEmpty())
        <details class="card">
            <summary><strong>Déjà remboursées ({{ $settled->count() }})</strong></summary>
            <ul class="stat-list" style="margin-top:.5rem">
                @foreach ($settled as $t)
                    <li>
                        <span><a href="{{ route('transactions.edit', $t) }}">{{ $t->label }}</a> <span class="small muted">· payé le {{ $t->occurred_on->format('d/m') }}, remboursé le {{ $t->claim_settled_on?->format('d/m/Y') }}</span></span>
                        <span class="loan-entry-actions">
                            <strong><x-money-amount :value="-$t->amount" /></strong>
                            <form method="POST" action="{{ route('claims.reopen', $t) }}">@csrf<button class="link-btn small" type="submit">Annuler</button></form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
