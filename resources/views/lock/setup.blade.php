@extends('layouts.app', ['title' => 'Bienvenue'])

@section('content')
    <div class="lock-screen">
        <x-icon name="piggy" class="icon lock-icon" />
        <h1>Bienvenue dans Argent</h1>
        <p class="muted">Vos comptes perso et pro, ce que vous gagnez et dépensez, vos budgets et objectifs. Les paiements et frais de l'app de devis y arrivent tout seuls chaque semaine.</p>

        <form method="POST" action="{{ route('setup.store') }}" class="card">
            @csrf
            <h2>1. Choisissez un code Argent</h2>
            <p class="small muted">De 4 à 8 chiffres, différent de votre mot de passe. Il sera demandé à chaque ouverture de l'app, et après quelques minutes sans l'utiliser.</p>
            <div class="field @error('code') has-error @enderror">
                <label for="code">Code Argent</label>
                <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required autofocus>
                @error('code')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field" style="margin-top:.75rem">
                <label for="code_confirmation">Le même code, une 2e fois</label>
                <input id="code_confirmation" class="pin-input" type="password" name="code_confirmation" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
            </div>

            <h2 style="margin-top:1.5rem">2. Combien avez-vous aujourd'hui ?</h2>
            <p class="small muted">Facultatif, modifiable ensuite (Comptes). Vous pourrez ajouter d'autres comptes : livret, espèces…</p>
            <div class="form-grid cols-2">
                <x-field name="perso_balance" label="Compte perso (€)" inputmode="decimal" placeholder="0,00" />
                <x-field name="pro_balance" label="Compte pro (€)" inputmode="decimal" placeholder="0,00" />
            </div>
            <h2 style="margin-top:1.5rem">3. Relier l'app de devis</h2>
            <p class="small muted">Facultatif, possible plus tard (Réglages). Dans l'app de devis : Réglages → Accès Claude → « Créer la clé de l'app Argent », puis collez la clé ici.</p>
            <div class="form-grid">
                <x-field name="devis_url" label="Adresse de l'app de devis" type="url" value="https://test.matts-couverture.fr" />
                <x-field name="devis_token" label="Clé de l'app Argent" type="password" autocomplete="off" placeholder="mc_…" />
            </div>
            <div class="form-actions"><button class="btn btn-block" type="submit"><x-icon name="lock" /> Commencer</button></div>
        </form>
    </div>
@endsection
