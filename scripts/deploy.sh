#!/bin/bash
# Mise à jour automatique de l'app Argent (o2switch), lancée par cron toutes les 10 minutes :
#   */10 * * * * /bin/bash $HOME/argent-source/scripts/deploy.sh
# Si GitHub a une nouvelle version : récupération, installation, migrations, caches,
# puis notification sur le téléphone. Sinon, rien. Journal : ~/argent-deploy.log
#
# Tout est dans main() : bash lit le script en entier avant de l'exécuter,
# même si la mise à jour remplace ce fichier pendant qu'il tourne.

main() {
    set -uo pipefail
    export PATH="/usr/local/bin:/usr/bin:/bin:$HOME/bin:$HOME/.local/bin:$PATH"

    local SRC="$HOME/argent-source"
    local APP="$HOME/argent"
    local LOG="$HOME/argent-deploy.log"

    # Une seule mise à jour à la fois.
    exec 9>"$HOME/.argent-deploy.lock"
    flock -n 9 || exit 0

    cd "$SRC" || exit 1
    git fetch -q origin 2>>"$LOG" || exit 0

    # Version réellement installée (et non la dernière téléchargée : un « git pull »
    # fait à la main ne doit pas empêcher l'installation complète).
    local LOCAL REMOTE STATE="$HOME/.argent-deployed-commit"
    LOCAL=$(cat "$STATE" 2>/dev/null)
    git cat-file -e "${LOCAL:-x}^{commit}" 2>/dev/null || LOCAL=$(git rev-parse HEAD)
    REMOTE=$(git rev-parse '@{u}') || exit 0
    # Première fois (aucune version enregistrée) : installation complète, une fois.
    [ -f "$STATE" ] && [ "$LOCAL" = "$REMOTE" ] && exit 0
    # Version déjà essayée sans succès : on attend la suivante (pas d'alerte toutes les 10 minutes).
    [ "$(cat "$HOME/.argent-deploy-failed" 2>/dev/null)" = "$REMOTE" ] && exit 0

    {
        echo "=== $(date '+%d/%m/%Y %H:%M') : ${LOCAL:0:7} -> ${REMOTE:0:7}"
        git reset -q --hard "$REMOTE" &&
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction -q &&
        rsync -a --exclude .git --exclude .env --exclude storage "$SRC/" "$APP/" &&
        cd "$APP" &&
        php artisan migrate --force &&
        php artisan config:cache &&
        php artisan route:cache &&
        php artisan view:cache
    } >>"$LOG" 2>&1

    if [ $? -eq 0 ]; then
        echo "OK" >>"$LOG"
        echo "$REMOTE" >"$STATE"
        rm -f "$HOME/.argent-deploy-failed"
        php "$APP/artisan" app:deployed "$(git -C "$SRC" log -1 --pretty=%s)" >>"$LOG" 2>&1
    else
        echo "ÉCHEC" >>"$LOG"
        echo "$REMOTE" >"$HOME/.argent-deploy-failed"
        # Retour à la version en service : copie source, puis application
        # (fichiers remis à l'identique, fichiers ajoutés par la version ratée retirés).
        git -C "$SRC" reset -q --hard "$LOCAL"
        {
            git -C "$SRC" diff --name-only --diff-filter=A "$LOCAL" "$REMOTE" | while read -r added; do
                case "$added" in storage/*|.env*) ;; *) rm -f "$APP/$added" ;; esac
            done
            rsync -a --exclude .git --exclude .env --exclude storage "$SRC/" "$APP/"
            cd "$APP" && php artisan config:cache && php artisan route:cache && php artisan view:cache
        } >>"$LOG" 2>&1
        php "$APP/artisan" app:deployed --echec >>"$LOG" 2>&1
    fi

    # Journal limité aux 500 dernières lignes.
    tail -n 500 "$LOG" >"$LOG.tmp" && mv "$LOG.tmp" "$LOG"
    exit 0
}

main "$@"
