#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-/usr/bin/php83}"
APP_DIR="${APP_DIR:-/www/htdocs/w021867a/airline.obermeier-it.de}"

cd "$APP_DIR"

if [[ ! -f .env ]]; then
    echo "FEHLER: $APP_DIR/.env fehlt. Kopiere zuerst .env.all-inkl.example nach .env und trage die KAS-Datenbankwerte ein." >&2
    exit 1
fi

if [[ ! -x "$PHP_BIN" ]]; then
    echo "FEHLER: PHP wurde unter $PHP_BIN nicht gefunden. Setze PHP_BIN auf den korrekten PHP-CLI-Pfad." >&2
    exit 1
fi

if [[ ! -f vendor/autoload.php ]]; then
    echo "Composer-Abhängigkeiten fehlen – installiere Produktionsabhängigkeiten ..."

    if ! command -v composer >/dev/null 2>&1; then
        echo "FEHLER: composer wurde im SSH-Pfad nicht gefunden." >&2
        exit 1
    fi

    composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress
fi

if [[ ! -f public/assets/airline-empire.css || ! -f public/assets/airline-empire.js ]]; then
    echo "FEHLER: Produktions-CSS oder UI-JavaScript fehlt. Führe zuerst 'git pull origin main' aus." >&2
    exit 1
fi

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if grep -q '^APP_KEY=$' .env; then
    "$PHP_BIN" artisan key:generate --force
fi

if ! grep -q '^SIMULATION_CRON_TOKEN=' .env || grep -q '^SIMULATION_CRON_TOKEN=$' .env; then
    SIMULATION_TOKEN="$($PHP_BIN -r 'echo bin2hex(random_bytes(32));')"

    if grep -q '^SIMULATION_CRON_TOKEN=' .env; then
        sed -i "s/^SIMULATION_CRON_TOKEN=.*/SIMULATION_CRON_TOKEN=${SIMULATION_TOKEN}/" .env
    else
        printf '\nSIMULATION_CRON_TOKEN=%s\n' "$SIMULATION_TOKEN" >> .env
    fi
fi

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force
"$PHP_BIN" artisan airline:airport-backfill
"$PHP_BIN" artisan airline:marketing-backfill
"$PHP_BIN" artisan airline:competition-backfill
"$PHP_BIN" artisan airline:revenue-backfill
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

SIMULATION_TOKEN="$(grep '^SIMULATION_CRON_TOKEN=' .env | cut -d= -f2-)"

printf '\nAirline Empire wurde für ALL-INKL aktiviert.\n'
printf 'Website: https://airline.obermeier-it.de\n'
printf 'Login:   https://airline.obermeier-it.de/login\n'
printf 'Health:  https://airline.obermeier-it.de/up\n'
printf 'API:     https://airline.obermeier-it.de/api/v1/health\n'
printf '\nSimulation-Cron (empfohlen alle 5 Minuten):\n'
printf 'https://airline.obermeier-it.de/system/cron/simulate?token=%s\n' "$SIMULATION_TOKEN"
