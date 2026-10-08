@extends('layouts.app', ['title' => 'Code Argent'])

@section('content')
    <div class="lock-screen">
        <x-icon name="lock" class="icon lock-icon" />
        <h1>App verrouillée</h1>
        <p class="muted">Tapez votre code Argent.</p>
        <form method="POST" action="{{ route('unlock.store') }}" class="card">
            @csrf
            <div class="field @error('code') has-error @enderror">
                <label for="code" class="visually-hidden">Code Argent</label>
                <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" maxlength="8" autocomplete="off" required autofocus @disabled($blockedFor > 0)>
                @error('code')<span class="error">{{ $message }}</span>@enderror
                @if ($blockedFor > 0 && ! $errors->has('code'))<span class="error">Trop de codes faux. Réessayez dans {{ max(1, (int) ceil($blockedFor / 60)) }} minute(s).</span>@endif
            </div>
            <div class="form-actions"><button class="btn btn-block" type="submit" @disabled($blockedFor > 0)>Ouvrir</button></div>
        </form>
        <p class="small"><a href="{{ route('forgot') }}">Code oublié ?</a></p>
        <form method="POST" action="{{ route('logout') }}" style="text-align:center">@csrf<button class="link-btn small" type="submit">Se déconnecter</button></form>
    </div>
@endsection
