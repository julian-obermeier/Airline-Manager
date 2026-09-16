# Setup – Airline Empire

## Voraussetzungen

- PHP 8.3 oder neuer
- Composer 2
- Node.js 22 oder neuer
- PostgreSQL 16+
- Redis 7+
- PHP-Erweiterungen für PostgreSQL und Redis

## Datenbank

Lege eine PostgreSQL-Datenbank und einen Benutzer an, zum Beispiel:

- Datenbank: `airline_manager`
- Benutzer: `airline_manager`

Die Zugangsdaten werden ausschließlich in `.env` gespeichert.

## Installation

```bash
cp .env.example .env
composer install
php artisan key:generate
npm install
php artisan migrate
npm run build
```

Für lokale Frontend-Entwicklung:

```bash
npm run dev
```

Laravel lokal starten:

```bash
php artisan serve
```

Queue Worker:

```bash
php artisan queue:work --tries=3 --timeout=120
```

Reverb:

```bash
php artisan reverb:start
```

Scheduler im lokalen Entwicklungsbetrieb:

```bash
php artisan schedule:work
```

## Webserver

Der Document Root muss auf `public/` zeigen. Niemals das Projektstammverzeichnis direkt öffentlich ausliefern.

## Health Checks

- Laravel Framework Health: `/up`
- API Health: `/api/v1/health`

## Tests und Qualitätsprüfung

```bash
composer test
npm run typecheck
npm run build
```

## Produktionsbetrieb

Vor einem Production Deploy mindestens:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Queue Worker und Reverb sollten über einen Process Supervisor/systemd oder eine gleichwertige Prozessverwaltung dauerhaft betrieben werden.

`APP_DEBUG` muss in Produktion `false` sein. Reverb Origins, Datenbankzugänge, Redis, Mail und Storage werden über die produktive `.env` konfiguriert und niemals committed.
