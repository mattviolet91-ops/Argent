#!/bin/bash
# Installation de l'app Argent sur o2switch, à lancer UNE fois dans le Terminal cPanel :
#
#   git clone https://github.com/mattviolet91-ops/Argent-.git ~/argent-source && bash ~/argent-source/scripts/install.sh
#
# Peut être relancé sans risque : rien n'est effacé (base, réglages et compte sont gardés).
set -eo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:$HOME/bin:$HOME/.local/bin:$PATH"

SRC="$HOME/argent-source"
APP="$HOME/argent"
URL="${ARGENT_URL:-https://argent.matts-couverture.fr}"

echo "== 1/6 Vérifications"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo "PHP 8.2 ou plus est nécessaire : cPanel → Sélectionner une version de PHP."; exit 1; }
php -m | grep -qi '^pdo_sqlite$' || { echo "Extension pdo_sqlite manquante : cPanel → Sélectionner une version de PHP → Extensions → cocher pdo_sqlite."; exit 1; }
command -v composer >/dev/null || { echo "Composer introuvable sur ce serveur."; exit 1; }
[ -d "$SRC/.git" ] || git clone -q https://github.com/mattviolet91-ops/Argent-.git "$SRC"

echo "== 2/6 Dépendances (1 à 2 minutes)"
cd "$SRC"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction -q

echo "== 3/6 Copie des fichiers dans $APP"
mkdir -p "$APP"
rsync -a --exclude .git --exclude .env --exclude storage "$SRC/" "$APP/"
mkdir -p "$APP/storage/app/private" "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/storage/logs"

echo "== 4/6 Configuration et base de données"
cd "$APP"
if [ ! -f .env ]; then
    sed -e "s#^APP_URL=.*#APP_URL=$URL#" -e "s#^DB_DATABASE=.*#DB_DATABASE=$APP/storage/argent.sqlite#" .env.o2switch.example > .env
    chmod 600 .env
    php artisan key:generate --force -q
fi
[ -f storage/argent.sqlite ] || { touch storage/argent.sqlite; chmod 600 storage/argent.sqlite; }
php artisan migrate --force

echo "== 5/6 Votre compte"
php artisan app:create-user --si-absent

echo "== 6/6 Tâches automatiques (sauvegardes, bilan du lundi, mises à jour)"
php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q
git -C "$SRC" rev-parse HEAD > "$HOME/.argent-deployed-commit"
PHP_BIN="$(command -v php)"
{
    crontab -l 2>/dev/null | grep -vF "cd $APP &&" | grep -vF "$SRC/scripts/deploy.sh" || true
    echo "* * * * * cd $APP && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
    echo "*/10 * * * * /bin/bash $SRC/scripts/deploy.sh"
} | crontab -

echo
echo "Terminé. Ouvrez $URL (après avoir activé le HTTPS : cPanel → Statut SSL/TLS → AutoSSL)."
