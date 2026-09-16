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

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

printf '\nAirline Empire wurde für ALL-INKL aktiviert.\n'
printf 'Health: https://airline.obermeier-it.de/up\n'
printf 'API:    https://airline.obermeier-it.de/api/v1/health\n'
