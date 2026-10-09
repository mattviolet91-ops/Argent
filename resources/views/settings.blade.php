@extends('layouts.app', ['title' => 'Réglages'])

@section('content')
    <div class="page-head"><div><h1>Réglages</h1></div></div>

    <form method="POST" action="{{ route('settings.devis') }}" class="card" id="devis" data-busy="Connexion à l'app de devis…">
        @csrf
        @method('PUT')
        <h2>Lien avec l'app de devis</h2>
        <p class="small muted">Chaque lundi matin, les paiements reçus et les frais des chantiers sont copiés ici (sans doublon), puis le bilan de la semaine est fait.
            La clé se crée dans l'app de devis : Réglages → Accès Claude → « Créer la clé de l'app Argent ». Elle ne permet que de lire ces chiffres.</p>
        @if ($devisLinked)
            <p class="small"><span class="badge badge-success">Relié</span>
                @if ($lastSync) Dernière mise à jour : {{ $lastSync->format('d/m/Y à H:i') }}{{ isset($lastResult['added']) ? ' ('.$lastResult['added'].' ajouté(s), '.$lastResult['updated'].' modifié(s), '.$lastResult['removed'].' retiré(s))' : '' }}.@endif
            </p>
        @endif
        @if ($devisError)
            <div class="alert alert-warning">Dernière mise à jour automatique impossible : {{ $devisError }}</div>
        @endif
        <div class="form-grid cols-2">
            <x-field name="devis_url" label="Adresse de l'app de devis" type="url" :value="$devisUrl" required />
            <x-field name="devis_token" label="Clé de l'app Argent" type="password" autocomplete="off" :placeholder="$devisLinked ? 'Clé enregistrée (laisser vide pour la garder)' : 'mc_…'" />
        </div>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="repeat" /> Enregistrer et mettre à jour</button></div>
    </form>

    <form method="POST" action="{{ route('settings.update') }}" class="card">
        @csrf
        @method('PUT')
        <h2>Comptes et sécurité</h2>
        <div class="form-grid cols-2">
            <div class="field">
                <label for="sync_account_id">Compte qui reçoit les paiements et les frais des devis</label>
                <select id="sync_account_id" name="sync_account_id">
                    <option value="">— Aucun —</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($syncAccount?->id === $account->id)>{{ $account->name }} ({{ $account->scopeLabel() }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="cash_account_id">Paiements en espèces sur <span class="muted small">(facultatif)</span></label>
                <select id="cash_account_id" name="cash_account_id">
                    <option value="">Le même compte</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($cashAccount?->id === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="lock_minutes">Verrouiller tout seul après</label>
                <select id="lock_minutes" name="lock_minutes">
                    @foreach (\App\Services\MoneyLockService::DELAYS as $minutes => $label)
                        <option value="{{ $minutes }}" @selected($lockMinutes === $minutes)>{{ $label }} sans activité</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label class="check" style="margin-top:.75rem"><input type="checkbox" name="lock_on_leave" value="1" @checked($lockOnLeave)> <span>Verrouiller dès que je quitte l'app plus de 30 secondes <span class="muted small">(autre application, écran éteint)</span></span></label>
        <h3 style="margin-top:1.25rem">Notifications</h3>
        <label class="check"><input type="checkbox" name="weekly_push" value="1" @checked($weeklyPush)> <span>Me prévenir quand le bilan de la semaine est prêt</span></label>
        <h3 style="margin-top:1.25rem">Alertes</h3>
        <label class="check"><input type="checkbox" name="alerts_enabled" value="1" @checked($alertsEnabled)> <span>Me prévenir quand un budget du mois est dépassé, ou qu'un compte passe sous son seuil <span class="muted small">(seuil à régler sur chaque compte)</span></span></label>
        <label class="check" style="margin-top:.5rem"><input type="checkbox" name="alerts_budget_warning" value="1" @checked($alertsBudgetWarning)> <span>Aussi quand un budget atteint 80 %</span></label>
        <label class="check" style="margin-top:.5rem"><input type="checkbox" name="alerts_reminders" value="1" @checked($alertsReminders)> <span>Rappels : une garantie se termine dans le mois, un remboursement est attendu</span></label>
        <label class="check" style="margin-top:.5rem"><input type="checkbox" name="push_amounts" value="1" @checked($pushAmounts)> <span>Montrer les montants dans les notifications <span class="muted small">(visibles sur l'écran verrouillé du téléphone)</span></span></label>
        <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
    </form>

    <div class="card" data-push data-push-key="{{ $pushKey }}" data-subscribe="{{ route('push.subscribe') }}"
        data-unsubscribe="{{ route('push.unsubscribe') }}" data-test="{{ route('push.test') }}">
        @csrf
        <h2>Notifications sur ce téléphone</h2>
        <p class="muted small">Pour le bilan du lundi et les alertes de sécurité. Sur iPhone, ouvrez d'abord l'app depuis son icône sur l'écran d'accueil (voir plus bas).</p>
        <p data-push-status role="status">Vérification…</p>
        <div class="form-actions">
            <button class="btn" type="button" data-push-enable hidden>Activer les notifications</button>
            <button class="btn btn-secondary" type="button" data-push-test hidden>Envoyer une notification de test</button>
            <button class="btn btn-secondary" type="button" data-push-disable hidden>Désactiver sur cet appareil</button>
        </div>
    </div>
    <script src="{{ asset('js/push.js') }}?v={{ filemtime(public_path('js/push.js')) }}" defer></script>

    <div class="card" id="faceid" data-faceid-register data-options="{{ route('faceid.options') }}" data-store="{{ route('faceid.store') }}">
        <h2>Face ID / empreinte</h2>
        <p class="small muted">Ouvrez l'app avec votre visage (ou votre doigt) au lieu du code. Une fois activé, le code seul ne suffit plus : en secours, il faut le code <strong>et</strong> le mot de passe. Rien de votre visage ne quitte le téléphone.</p>
        @if ($faceIdDevices->isNotEmpty())
            <ul class="stat-list">
                @foreach ($faceIdDevices as $device)
                    <li>
                        <span>{{ $device->device ?? 'Appareil' }} <span class="muted small">· ajouté le {{ $device->created_at->format('d/m/Y') }}{{ $device->last_used_at ? ' · utilisé le '.$device->last_used_at->format('d/m/Y à H:i') : '' }}</span></span>
                        <form method="POST" action="{{ route('faceid.destroy', $device) }}" data-confirm="Retirer cet appareil ? Il ne pourra plus ouvrir l'app avec Face ID.">
                            @csrf
                            @method('DELETE')
                            <button class="link-btn small" type="submit">Retirer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="small" data-faceid-message role="status"></p>
        <button class="btn" type="button" data-faceid-add><x-icon name="faceid" /> Activer Face ID sur cet appareil</button>
    </div>
    <script src="{{ asset('js/faceid.js') }}?v={{ filemtime(public_path('js/faceid.js')) }}" defer></script>

    <div class="card" id="hors-ligne" data-offline-settings>
        <h2>Mode hors ligne</h2>
        <p class="small muted">Sans réseau (chantier, sous-sol, avion…), l'app s'ouvre quand même sur ce téléphone : soldes, derniers mouvements et échéances de la dernière ouverture, et vous pouvez noter des dépenses, ajoutées toutes seules au retour du réseau.
            Ces données restent sur le téléphone, chiffrées avec votre code Argent : sans le code, elles sont illisibles. 10 codes faux hors ligne les effacent.</p>
        <p data-offline-status class="small" role="status">Vérification…</p>
        <form data-offline-form action="{{ route('offline.activate') }}" class="offline-activate">
            <div class="field">
                <label for="offline-code">Votre code Argent</label>
                <input id="offline-code" class="pin-input" type="password" inputmode="numeric" maxlength="8" autocomplete="off" data-offline-code required>
            </div>
            <button class="btn" type="submit">Activer sur cet appareil</button>
        </form>
        <button class="btn btn-sm btn-danger-outline" type="button" data-offline-off data-url="{{ route('offline.deactivate') }}" hidden style="margin-top:.75rem">Désactiver et effacer de cet appareil</button>
    </div>

    <div class="card">
        <h2>Mettre à jour maintenant</h2>
        <p class="small muted">Sans attendre lundi : paiements et frais de l'app de devis, et dépenses fixes arrivées à échéance.</p>
        <form method="POST" action="{{ route('sync') }}" data-busy="Mise à jour…">
            @csrf
            <button class="btn btn-secondary" type="submit"><x-icon name="repeat" /> Mettre à jour</button>
        </form>
    </div>

    <div class="grid grid-2">
        <form method="POST" action="{{ route('settings.code') }}" class="card">
            @csrf
            @method('PUT')
            <h2>Changer le code Argent</h2>
            <div class="form-grid">
                <div class="field @error('current_code') has-error @enderror">
                    <label for="current_code">Code actuel</label>
                    <input id="current_code" class="pin-input" type="password" name="current_code" inputmode="numeric" maxlength="8" autocomplete="off" required>
                    @error('current_code')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field @error('code') has-error @enderror">
                    <label for="code">Nouveau code (4 à 8 chiffres)</label>
                    <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
                    @error('code')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="code_confirmation">Le même, une 2e fois</label>
                    <input id="code_confirmation" class="pin-input" type="password" name="code_confirmation" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
                </div>
            </div>
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="lock" /> Changer le code</button></div>
        </form>

        <form method="POST" action="{{ route('settings.password') }}" class="card">
            @csrf
            @method('PUT')
            <h2>Changer le mot de passe</h2>
            <p class="small muted">Celui de la connexion. Les autres appareils seront déconnectés.</p>
            <div class="form-grid">
                <x-field name="current_password" label="Mot de passe actuel" type="password" autocomplete="current-password" required />
                <x-field name="password" label="Nouveau (12 caractères minimum, lettres et chiffres)" type="password" autocomplete="new-password" required />
                <x-field name="password_confirmation" label="Le même, une 2e fois" type="password" autocomplete="new-password" required />
            </div>
            <div class="form-actions"><button class="btn" type="submit">Changer le mot de passe</button></div>
        </form>
    </div>

    <div class="card">
        <h2>Vos données</h2>
        <p class="small muted">Tout est gardé sur votre serveur. Une sauvegarde complète est faite chaque nuit (les 30 dernières sont gardées) : téléchargez-en une de temps en temps pour la garder chez vous.</p>
        <div class="form-actions" style="margin-top:0">
            <a class="btn btn-secondary" href="{{ route('export') }}"><x-icon name="file" /> Télécharger mes mouvements (Excel)</a>
            <a class="btn btn-secondary" href="{{ route('import.create') }}"><x-icon name="upload" /> Importer un relevé</a>
        </div>
        @if ($backups->isNotEmpty())
            <ul class="stat-list" style="margin-top:1rem">
                @foreach ($backups as $backup)
                    <li><span>Sauvegarde du {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['date'])->timezone(config('app.timezone'))->format('d/m/Y à H:i') }} <span class="muted small">· {{ number_format($backup['size'] / 1024, 0, ',', ' ') }} Ko</span></span>
                        <a class="small" href="{{ route('backups.download', $backup['name']) }}">Télécharger</a></li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card">
        <h2>Installer l'app sur le téléphone</h2>
        <ul class="small">
            <li><strong>iPhone</strong> (Safari) : bouton Partager <span aria-hidden="true">⬆︎</span> → « Sur l'écran d'accueil ».</li>
            <li><strong>Android</strong> (Chrome) : menu ⋮ → « Installer l'application » (ou « Ajouter à l'écran d'accueil »).</li>
        </ul>
        <p class="small muted" style="margin-bottom:0">L'icône verte « Argent » s'ouvre comme une vraie application, séparée de celle des devis.</p>
    </div>

    <div class="card">
        <h2>Journal de sécurité</h2>
        <p class="small muted">Les dernières connexions et actions sensibles. Une ligne que vous ne reconnaissez pas ? Changez le mot de passe et le code.</p>
        <ul class="stat-list">
            @forelse ($journal as $entry)
                <li><span>{{ $entry->description }} <span class="muted small">· {{ $entry->ip }}</span></span><span class="small muted">{{ $entry->created_at?->format('d/m/Y H:i') }}</span></li>
            @empty
                <li><span class="muted">Rien pour l'instant.</span></li>
            @endforelse
        </ul>
    </div>
@endsection
