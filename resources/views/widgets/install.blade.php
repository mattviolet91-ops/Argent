@extends('layouts.app', ['title' => 'Installer le widget'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Installer le widget « {{ $widget->name }} »</h1>
            <p>Sur l'iPhone, en 5 minutes. Vue : {{ $widget->viewLabel() }}{{ $widget->show_amounts ? '' : ', montants masqués' }}.</p>
        </div>
    </div>

    @if (! $script)
        <div class="card empty">
            <x-icon name="lock" />
            <h2>Le script n'est plus affiché</h2>
            <p>Pour votre sécurité, la clé d'un widget n'est montrée qu'une fois, juste après sa création. Widget pas encore installé ? Retirez celui-ci et créez-en un autre (Réglages → Widgets iPhone).</p>
            <p><a class="btn btn-secondary" href="{{ route('settings') }}#widgets"><x-icon name="settings" /> Réglages</a></p>
        </div>
    @else
        <div class="card">
            <ol class="howto-list widget-steps">
                <li>
                    <strong>Installez l'app gratuite Scriptable</strong> depuis l'App Store.
                    <p><a class="btn btn-secondary btn-sm" href="https://apps.apple.com/app/scriptable/id1405459188" rel="noopener noreferrer" target="_blank">Scriptable sur l'App Store</a></p>
                </li>
                <li>
                    <strong>Copiez le script</strong> de votre widget :
                    <p><button class="btn btn-sm" type="button" data-copy="#widget-script" data-copied="Copié ✓"><x-icon name="copy" /> Copier le script</button></p>
                    <details class="widget-script">
                        <summary class="small muted">Voir le script</summary>
                        <textarea id="widget-script" readonly rows="10" spellcheck="false" aria-label="Script du widget">{{ $script }}</textarea>
                    </details>
                </li>
                <li>
                    <strong>Collez-le dans Scriptable :</strong> ouvrez Scriptable, touchez <strong>+</strong> en haut à droite, touchez la page puis <strong>Coller</strong>.
                    En haut, touchez le titre (« Untitled Script ») et renommez-le <strong>Argent</strong>, puis <strong>Done</strong>.
                    Touchez ▶︎ en bas : un aperçu doit s'afficher.
                    <p><a class="btn btn-secondary btn-sm" href="scriptable:///add">Ouvrir Scriptable</a></p>
                </li>
                <li>
                    <strong>Ajoutez le widget sur l'écran d'accueil :</strong> appui long sur un espace vide → <strong>Modifier</strong> (ou <strong>+</strong>) → <strong>Ajouter un widget</strong> → cherchez <strong>Scriptable</strong> → choisissez la taille (petit, moyen ou grand) → <strong>Ajouter le widget</strong>.
                    Puis appui long sur ce widget → <strong>Modifier le widget</strong> → <strong>Script</strong> : choisissez <strong>Argent</strong>.
                    @if ($widget->scope === 'all')
                        <p class="small muted">Un widget pour le perso et un autre pour le pro ? Ajoutez-en deux, et dans <strong>Parameter</strong> tapez <code>perso</code> sur l'un et <code>pro</code> sur l'autre.</p>
                    @endif
                </li>
                <li>
                    <strong>Sur l'écran verrouillé aussi</strong> (facultatif) : appui long sur l'écran verrouillé → <strong>Personnaliser</strong> → <strong>Écran verrouillé</strong> → touchez la zone des widgets → <strong>Scriptable</strong> → choisissez une forme, touchez-la → <strong>Script</strong> : Argent.
                </li>
            </ol>
            <p class="money-note">Le widget se met à jour tout seul : l'iPhone choisit le moment, en général toutes les 15 à 30 minutes. Sans réseau, il garde le dernier résumé reçu (avec ⚠︎).
                Toucher le widget ouvre Argent dans Safari : il faudra peut-être vous y connecter une fois, puis le code Argent est demandé comme d'habitude.</p>
            <p class="money-note"><strong>Ne partagez pas ce script :</strong> il contient la clé du widget. Elle ne sert qu'à lire ce résumé, rien à modifier ni les mouvements en détail, et ne s'affiche plus après cette page.</p>
            <form method="POST" action="{{ route('settings.widgets.done') }}" class="form-actions">
                @csrf
                <button class="btn" type="submit"><x-icon name="check" /> C'est installé</button>
            </form>
        </div>
    @endif
@endsection
