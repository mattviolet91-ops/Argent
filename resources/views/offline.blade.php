@extends('layouts.base', ['title' => 'Hors ligne'])

{{-- Page de l'app sans réseau, gardée par le téléphone. Aucune donnée ici : tout vient du résumé chiffré du téléphone, ouvert avec le code. --}}
@section('body')
<div class="app" data-offline-shell>
    <header class="topbar">
        <span class="brand">
            <img class="brand-img-icon" src="{{ asset('icons/icon-192.png') }}" alt="">
            <span class="brand-name">Argent</span>
        </span>
        <span class="badge badge-warning offline-badge">Hors ligne</span>
        <span class="spacer"></span>
        <button class="icon-btn" type="button" data-theme-toggle title="Mode clair / sombre">
            <x-icon name="moon" class="icon theme-moon" /><x-icon name="sun" class="icon theme-sun" /><span class="visually-hidden">Mode clair / sombre</span>
        </button>
        <button class="icon-btn" type="button" data-offline-lock title="Verrouiller"><x-icon name="lock" /><span class="visually-hidden">Verrouiller</span></button>
    </header>

    <main class="main offline-main">
        <div class="container">
            <div class="alert alert-success offline-online" data-part="online" hidden>
                <strong>Le réseau est revenu.</strong>
                <button class="btn btn-sm" type="button" data-offline-retry>Ouvrir l'app</button>
                <span class="small">Les mouvements notés ici y seront ajoutés.</span>
            </div>

            <div class="card offline-center" data-part="unsupported" hidden>
                <h1>Pas de connexion</h1>
                <p class="muted">Ce navigateur ne permet pas le mode hors ligne. Réessayez quand vous aurez du réseau.</p>
                <button class="btn" type="button" data-offline-retry>Réessayer</button>
            </div>

            <div class="card offline-center" data-part="setup" hidden>
                <h1>Pas de connexion</h1>
                <p class="muted">L'app Argent a besoin du réseau. Pour l'ouvrir aussi sans réseau, activez le mode hors ligne dans Réglages la prochaine fois que vous aurez du réseau.</p>
                <button class="btn" type="button" data-offline-retry><x-icon name="repeat" /> Réessayer</button>
            </div>

            <form class="card offline-center" data-part="lock" data-offline-unlock hidden>
                <h1>Pas de connexion</h1>
                <p class="muted">Tapez votre code Argent pour voir vos comptes (dernière mise à jour) et noter des dépenses.</p>
                <label class="visually-hidden" for="offline-pin">Code Argent</label>
                <input id="offline-pin" class="pin-input" type="password" inputmode="numeric" maxlength="8" autocomplete="off" required autofocus>
                <p class="small m-neg" data-offline-message role="status"></p>
                <button class="btn btn-block" type="submit"><x-icon name="lock" /> Ouvrir</button>
                <button class="btn btn-secondary btn-block" type="button" data-offline-retry style="margin-top:.5rem"><x-icon name="repeat" /> Réessayer avec le réseau</button>
            </form>

            <div data-part="main" hidden>
                <div class="page-head"><div><h1>Hors ligne</h1></div></div>
                <div data-render></div>
            </div>
        </div>
    </main>
</div>
<script src="{{ asset('js/offline.js') }}?v={{ filemtime(public_path('js/offline.js')) }}" defer></script>
@endsection
