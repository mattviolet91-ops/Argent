@extends('layouts.app', ['title' => 'Mouvement'])

@php
    $fromQuotes = $transaction->source === 'devis';
    $loan = $transaction->source === 'loan' ? \App\Models\MoneyLoanEntry::query()->where('transaction_id', $transaction->id)->with('person')->first() : null;
    $locked = $fromQuotes || $transaction->source === 'loan';
    $type = $transaction->amount >= 0 ? 'income' : 'expense';
@endphp

@section('content')
    @include('_nav')

    <div class="card">
        <div class="card-head">
            <h2>{{ $loan ? $loan->label() : ($transaction->isTransfer() ? 'Virement' : ($type === 'income' ? 'Revenu' : 'Dépense')) }}</h2>
            <span class="badge">{{ \App\Models\MoneyTransaction::SOURCES[$transaction->source] ?? '' }}</span>
        </div>
        @if ($fromQuotes)
            <div class="alert alert-info">Vient de l'app de devis (paiement ou frais) : le montant, la date et le compte se changent là-bas. Ici, vous pouvez changer la catégorie, le libellé et la note.</div>
        @endif
        @if ($loan)
            <div class="alert alert-info">Noté depuis « Qui me doit quoi » : le montant et la date se changent sur la fiche de <a href="{{ route('loans.show', $loan->person_id) }}">{{ $loan->person?->name }}</a>. Ni gagné, ni dépensé.</div>
        @endif
        @if ($transaction->isTransfer() && $peer)
            <p class="muted small">{{ $transaction->amount < 0 ? 'Vers' : 'Depuis' }} « {{ $peer->account?->name }} ». Les deux côtés du virement sont modifiés ensemble.</p>
        @endif

        <form method="POST" action="{{ route('transactions.update', $transaction) }}" data-money-switch="type">
            @csrf
            @method('PUT')
            @unless ($locked || $transaction->isTransfer())
                <div class="type-switch two" role="radiogroup" aria-label="Type">
                    <label><input type="radio" name="type" value="expense" @checked(old('type', $type) === 'expense')> Dépense</label>
                    <label><input type="radio" name="type" value="income" @checked(old('type', $type) === 'income')> Revenu</label>
                </div>
            @else
                <input type="hidden" name="type" value="{{ $type }}">
            @endunless
            <div class="form-grid cols-2">
                @unless ($locked)
                    <div class="field @error('amount') has-error @enderror">
                        <label for="amount">Montant (€)</label>
                        <input id="amount" class="amount-input" type="text" name="amount" value="{{ old('amount', \App\Support\Money::format(abs($transaction->amount), false)) }}" inputmode="decimal" required>
                        @error('amount')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <x-field name="occurred_on" label="Date" type="date" :value="$transaction->occurred_on->toDateString()" required />
                    @unless ($transaction->isTransfer())
                        <x-select name="account_id" label="Compte" :options="$accountOptions->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.$a->scopeLabel().')'])" :value="$transaction->account_id" :placeholder="false" />
                    @endunless
                @else
                    <div class="field"><span class="label">Montant</span><strong><x-money-amount :value="$transaction->amount" signed /></strong></div>
                    <div class="field"><span class="label">Date · compte</span><span>{{ $transaction->occurred_on->format('d/m/Y') }} · {{ $transaction->account?->name }}</span></div>
                @endunless
                @unless ($transaction->isTransfer())
                    <div class="field @error('category_id') has-error @enderror">
                        <label for="category_id">Catégorie</label>
                        <select id="category_id" name="category_id" @unless ($fromQuotes) data-filter-options @endunless>
                            <option value="">— Sans catégorie —</option>
                            @foreach ($categoryOptions->when($fromQuotes, fn ($c) => $c->where('type', $type)) as $category)
                                <option value="{{ $category->id }}" data-when="{{ $category->type }}" @selected((string) old('category_id', $transaction->category_id) === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id')<span class="error">{{ $message }}</span>@enderror
                    </div>
                @endunless
                <x-field name="label" label="Libellé" :value="$transaction->label" required maxlength="160" />
                <div class="field @error('tags') has-error @enderror">
                    <label for="tags">Chantier / projet</label>
                    <input id="tags" type="text" name="tags" value="{{ old('tags', $transaction->tags->pluck('name')->implode(', ')) }}" list="edit-tag-list" maxlength="300" autocomplete="off" placeholder="ex. Dupont, Salle de bain">
                    <datalist id="edit-tag-list">@foreach ($tagNames as $tagName)<option value="{{ $tagName }}"></option>@endforeach</datalist>
                    <span class="hint">Plusieurs : séparez par des virgules.</span>
                    @error('tags')<span class="error">{{ $message }}</span>@enderror
                </div>
                <x-field name="notes" label="Note" :value="$transaction->notes" maxlength="500" class="span-2" />
                @unless ($locked || $transaction->isTransfer())
                    <label class="check span-2" data-when="expense"><input type="checkbox" name="claim" value="1" @checked(old('claim', $transaction->isClaim()))>
                        <span>Note de frais : dépense pro payée avec un compte perso, à me faire rembourser
                            @if ($transaction->claim === 'rembourse')<span class="badge badge-success">remboursée le {{ $transaction->claim_settled_on?->format('d/m/Y') }}</span>@endif
                        </span></label>
                @endunless
            </div>
            <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
    </div>

    <div class="card" id="justificatifs" style="margin-top:1rem">
        <div class="card-head"><h2>Justificatifs</h2><span class="small muted">{{ $transaction->attachments->count() }} / {{ \App\Models\MoneyAttachment::MAX_PER_TRANSACTION }}</span></div>
        @if ($transaction->attachments->isNotEmpty())
            <div class="attach-grid">
                @foreach ($transaction->attachments as $file)
                    <div class="attach-item">
                        <a href="{{ route('attachments.show', $file) }}" target="_blank" rel="noopener" class="attach-preview">
                            @if ($file->isImage())<img src="{{ route('attachments.show', $file) }}" alt="{{ $file->name }}" loading="lazy">@else<x-icon name="file" /><span class="small">{{ \Illuminate\Support\Str::limit($file->name, 22) }}</span>@endif
                        </a>
                        <form method="POST" action="{{ route('attachments.destroy', $file) }}" data-confirm="Retirer ce justificatif ?">@csrf @method('DELETE')<button class="link-btn small" type="submit">Retirer</button></form>
                    </div>
                @endforeach
            </div>
        @else
            <p class="muted small" style="margin-top:0">Photo du ticket, facture… gardée sur votre serveur, visible seulement dans l'app.</p>
        @endif
        @if ($transaction->attachments->count() < \App\Models\MoneyAttachment::MAX_PER_TRANSACTION)
            <form method="POST" action="{{ route('attachments.store', $transaction) }}" enctype="multipart/form-data" class="attach-form" data-busy="Envoi…">
                @csrf
                <input type="file" name="justificatif" accept="image/*,application/pdf" data-shrink-image required aria-label="Photo ou PDF">
                <button class="btn btn-sm btn-secondary" type="submit"><x-icon name="paperclip" /> Joindre</button>
            </form>
            @error('justificatif')<p class="error">{{ $message }}</p>@enderror
        @endif
    </div>

    @if (! $transaction->isTransfer() && $transaction->amount < 0)
        <p style="margin-top:1rem"><a class="btn btn-secondary btn-sm" href="{{ route('purchases.index', ['mouvement' => $transaction->id]) }}"><x-icon name="receipt" /> Garder la facture et la garantie de cet achat</a></p>
    @endif

    @unless ($fromQuotes)
        <form method="POST" action="{{ route('transactions.destroy', $transaction) }}" data-confirm="Supprimer ce mouvement{{ $transaction->isTransfer() ? ' (les deux côtés du virement)' : '' }} ?" style="margin-top:1rem">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger-outline" type="submit"><x-icon name="trash" /> Supprimer</button>
        </form>
    @endunless
@endsection
