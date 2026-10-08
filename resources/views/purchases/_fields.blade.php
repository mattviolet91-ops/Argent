{{-- Champs d'un achat (création et modification). $purchase : achat ou null ; $prefill : valeurs proposées. --}}
@php
    $prefill ??= [];
    $warranty = old('warranty', $purchase ? ($purchase->warranty_until ? 'date' : '') : ($prefill['warranty'] ?? '24'));
@endphp
<div class="form-grid cols-2">
    <x-field name="name" label="Ce que vous avez acheté" :value="$purchase?->name ?? ($prefill['name'] ?? '')" required maxlength="120" placeholder="ex. Perceuse, lave-linge, téléphone" />
    <x-field name="shop" label="Magasin" :value="$purchase?->shop ?? ($prefill['shop'] ?? '')" maxlength="120" placeholder="facultatif" />
    <x-field name="purchased_on" label="Date d'achat" type="date" :value="$purchase?->purchased_on?->toDateString() ?? ($prefill['purchased_on'] ?? today()->toDateString())" required />
    <x-field name="price" label="Prix (€)" inputmode="decimal" :value="$purchase?->amount ? \App\Support\Money::format($purchase->amount, false) : ($prefill['amount'] ?? '')" placeholder="facultatif" />
    <div class="field @error('warranty') has-error @enderror">
        <label for="warranty{{ $purchase?->id }}">Garantie</label>
        <select id="warranty{{ $purchase?->id }}" name="warranty">
            @foreach (\App\Models\MoneyPurchase::WARRANTIES as $key => $text)
                <option value="{{ $key }}" @selected((string) $warranty === (string) $key)>{{ $text }}</option>
            @endforeach
        </select>
    </div>
    <div class="field @error('warranty_until') has-error @enderror" data-when="date">
        <label for="warranty_until{{ $purchase?->id }}">Garantie jusqu'au</label>
        <input id="warranty_until{{ $purchase?->id }}" type="date" name="warranty_until" value="{{ old('warranty_until', $purchase?->warranty_until?->toDateString()) }}">
        @error('warranty_until')<span class="error">{{ $message }}</span>@enderror
    </div>
    <div class="field span-2 @error('file') has-error @enderror">
        <label for="file{{ $purchase?->id }}">{{ $purchase?->hasFile() ? 'Remplacer la facture' : 'Facture ou ticket' }} <span class="muted small">(photo ou PDF)</span></label>
        <input id="file{{ $purchase?->id }}" type="file" name="file" accept="image/*,application/pdf" data-shrink-image>
        <span class="hint">Sur le téléphone : prenez la facture en photo. Elle est gardée sur votre serveur, visible seulement dans l'app.</span>
        @error('file')<span class="error">{{ $message }}</span>@enderror
    </div>
    <x-field name="details" label="Note" type="textarea" :value="$purchase?->notes" maxlength="500" class="span-2" rows="2" placeholder="n° de série, contact du SAV…" />
</div>
@if (! empty($prefill['transaction_id']))
    <input type="hidden" name="transaction_id" value="{{ $prefill['transaction_id'] }}">
@endif
