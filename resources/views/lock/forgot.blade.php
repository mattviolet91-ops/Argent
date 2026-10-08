@extends('layouts.app', ['title' => 'Code Argent oublié'])

@section('content')
    <div class="lock-screen">
        <x-icon name="lock" class="icon lock-icon" />
        <h1>Code Argent oublié</h1>
        <p class="muted">Tapez le mot de passe de votre compte (celui de la connexion), puis choisissez un nouveau code.</p>
        <form method="POST" action="{{ route('forgot.store') }}" class="card">
            @csrf
            <x-field name="password" label="Mot de passe du compte" type="password" autocomplete="current-password" required />
            <div class="field @error('code') has-error @enderror" style="margin-top:.75rem">
                <label for="code">Nouveau code Argent (4 à 8 chiffres)</label>
                <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
                @error('code')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field" style="margin-top:.75rem">
                <label for="code_confirmation">Le même code, une 2e fois</label>
                <input id="code_confirmation" class="pin-input" type="password" name="code_confirmation" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-actions"><button class="btn btn-block" type="submit">Enregistrer le nouveau code</button></div>
        </form>
        <p class="small"><a href="{{ route('unlock') }}">Retour</a></p>
    </div>
@endsection
