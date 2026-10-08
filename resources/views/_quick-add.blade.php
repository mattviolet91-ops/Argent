{{-- Ajout rapide : dépense, revenu ou virement entre comptes. Besoin de $accountOptions, $categoryOptions. --}}
@php
    $defaultAccount = old('account_id', $defaultAccount ?? $accountOptions->firstWhere('scope', ($scope ?? 'all') === 'pro' ? 'pro' : 'perso')?->id ?? $accountOptions->first()?->id);
    $hasErrors = $errors->hasAny(['type', 'amount', 'account_id', 'to_account_id', 'category_id', 'label', 'occurred_on', 'notes']);
@endphp
<dialog class="sheet sheet-tall" id="money-add" aria-labelledby="money-add-title" @if ($hasErrors) data-open-on-load @endif>
    <div class="card-head">
        <h2 id="money-add-title">Ajouter un mouvement</h2>
        <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
    </div>
    @if ($accountOptions->isEmpty())
        <p class="muted">Ajoutez d'abord un compte.</p>
        <a class="btn" href="{{ route('accounts.index') }}">Ajouter un compte</a>
    @else
        <form method="POST" action="{{ route('transactions.store') }}" data-money-switch="type">
            @csrf
            <div class="type-switch" role="radiogroup" aria-label="Type de mouvement">
                @foreach (['expense' => 'Dépense', 'income' => 'Revenu', 'transfer' => 'Virement'] as $key => $label)
                    <label><input type="radio" name="type" value="{{ $key }}" @checked(old('type', 'expense') === $key)> {{ $label }}</label>
                @endforeach
            </div>
            <div class="field @error('amount') has-error @enderror">
                <label for="add-amount">Montant (€) <span aria-hidden="true">*</span></label>
                <input id="add-amount" class="amount-input" type="text" name="amount" value="{{ old('amount') }}" inputmode="decimal" placeholder="0,00" autocomplete="off" required>
                @error('amount')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="form-grid cols-2" style="margin-top:1rem">
                <div class="field @error('account_id') has-error @enderror">
                    <label for="add-account"><span data-when="expense income">Compte</span><span data-when="transfer">Depuis le compte</span></label>
                    <select id="add-account" name="account_id">
                        @foreach ($accountOptions as $account)
                            <option value="{{ $account->id }}" @selected((string) $defaultAccount === (string) $account->id)>{{ $account->name }} ({{ $account->scopeLabel() }})</option>
                        @endforeach
                    </select>
                    @error('account_id')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field @error('to_account_id') has-error @enderror" data-when="transfer">
                    <label for="add-to-account">Vers le compte</label>
                    <select id="add-to-account" name="to_account_id">
                        <option value="">—</option>
                        @foreach ($accountOptions as $account)
                            <option value="{{ $account->id }}" @selected((string) old('to_account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->scopeLabel() }})</option>
                        @endforeach
                    </select>
                    @error('to_account_id')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field @error('category_id') has-error @enderror" data-when="expense income">
                    <label for="add-category">Catégorie</label>
                    <select id="add-category" name="category_id" data-filter-options>
                        <option value="">— Sans catégorie —</option>
                        @foreach ($categoryOptions as $category)
                            <option value="{{ $category->id }}" data-when="{{ $category->type }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<span class="error">{{ $message }}</span>@enderror
                </div>
                <x-field name="label" label="Libellé" placeholder="ex. Courses Leclerc, loyer…" maxlength="160" />
                <x-field name="occurred_on" label="Date" type="date" :value="today()->toDateString()" required />
                <x-field name="notes" label="Note" placeholder="facultatif" maxlength="500" />
            </div>
            <div class="form-actions"><button class="btn btn-block" type="submit"><x-icon name="check" /> Enregistrer</button></div>
        </form>
    @endif
</dialog>
