@extends('layouts.app', ['title' => 'Ouvrir Argent'])

@section('content')
    <div class="lock-screen">
        @php $blocked = $blockedFor > 0; @endphp
        @if ($faceId)
            <x-icon name="faceid" class="icon lock-icon" />
            <h1>App verrouillée</h1>
            <div class="card" data-faceid-unlock data-options="{{ route('unlock.faceid.options') }}" data-verify="{{ route('unlock.faceid') }}">
                <button class="btn btn-block faceid-btn" type="button" data-faceid-start @disabled($blocked)><x-icon name="faceid" /> Ouvrir avec Face ID</button>
                <p class="small muted" data-faceid-status role="status" style="margin:.75rem 0 0;text-align:center">
                    @if ($blocked)
                        Trop d'essais faux. Réessayez dans {{ max(1, (int) ceil($blockedFor / 60)) }} minute(s).
                    @else
                        Ou avec votre empreinte, selon le téléphone.
                    @endif
                </p>
            </div>

            <details class="card" style="text-align:left" @if ($errors->hasAny(['code', 'password'])) open @endif>
                <summary>Face ID ne marche pas ? Ouvrir avec le code</summary>
                <p class="small muted">Avec Face ID activé, il faut le code Argent <strong>et</strong> le mot de passe du compte.</p>
                <form method="POST" action="{{ route('unlock.store') }}" class="form-grid">
                    @csrf
                    <div class="field @error('code') has-error @enderror">
                        <label for="code">Code Argent</label>
                        <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" maxlength="8" autocomplete="off" required @disabled($blocked)>
                        @error('code')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <x-field name="password" label="Mot de passe du compte" type="password" autocomplete="current-password" required />
                    <button class="btn btn-block btn-secondary" type="submit" @disabled($blocked)>Ouvrir</button>
                </form>
            </details>
        @else
            <x-icon name="lock" class="icon lock-icon" />
            <h1>App verrouillée</h1>
            <p class="muted">Tapez votre code Argent.</p>
            <form method="POST" action="{{ route('unlock.store') }}" class="card">
                @csrf
                <div class="field @error('code') has-error @enderror">
                    <label for="code" class="visually-hidden">Code Argent</label>
                    <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" maxlength="8" autocomplete="off" required autofocus @disabled($blocked)>
                    @error('code')<span class="error">{{ $message }}</span>@enderror
                    @if ($blocked && ! $errors->has('code'))<span class="error">Trop de codes faux. Réessayez dans {{ max(1, (int) ceil($blockedFor / 60)) }} minute(s).</span>@endif
                </div>
                <div class="form-actions"><button class="btn btn-block" type="submit" @disabled($blocked)>Ouvrir</button></div>
            </form>
            <p class="small muted">Astuce : activez Face ID (Réglages) pour ouvrir l'app avec votre visage.</p>
        @endif
        <p class="small"><a href="{{ route('forgot') }}">Code oublié ?</a></p>
        <form method="POST" action="{{ route('logout') }}" style="text-align:center">@csrf<button class="link-btn small" type="submit">Se déconnecter</button></form>
    </div>
    <script src="{{ asset('js/faceid.js') }}?v={{ filemtime(public_path('js/faceid.js')) }}" defer></script>
@endsection
