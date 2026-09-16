# Setup – Airline Empire

## Voraussetzungen für lokale Entwicklung

- PHP 8.3 oder neuer
- Composer 2
- Node.js 22 oder neuer
- MySQL 8+ oder MariaDB 10.3+
- PHP-Erweiterung `pdo_mysql`

Redis ist **optional** und für den aktuellen Shared-Hosting-/Foundation-Betrieb nicht erforderlich.

## Datenbank

Lege lokal eine MySQL-/MariaDB-Datenbank und einen Benutzer an, zum Beispiel:

- Datenbank: `airline_manager`
- Benutzer: `airline_manager`

Die Zugangsdaten werden ausschließlich in `.env` gespeichert.

## Lokale Installation

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

## Shared-Hosting-Profil

Standardmäßig verwendet die Anwendung:

```env
DB_CONNECTION=mysql
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=log
```

Damit werden weder Redis noch dauerhaft laufende Queue- oder WebSocket-Prozesse benötigt.

Für ALL-INKL liegt eine eigene Produktionsvorlage unter `.env.all-inkl.example` vor.

## Spätere Server-Skalierung

Auf einem geeigneten Managed-/dedizierten Server können optional aktiviert werden:

```bash
php artisan queue:work --tries=3 --timeout=120
php artisan reverb:start
php artisan schedule:work
```

Diese Prozesse gehören **nicht** zum ALL-INKL-Shared-Hosting-Profil.

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

Die GitHub-CI testet Migrationen und PHP-Code gegen MariaDB und baut außerdem ein fertiges ALL-INKL-Deployment-Archiv.

## Produktionsbetrieb

Vor einem Production Deploy mindestens:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`APP_DEBUG` muss in Produktion `false` sein. Datenbankzugänge, Mail- und andere Secrets werden über die produktive `.env` konfiguriert und niemals committed.

Für das konkrete Zielsystem siehe [`deployment-all-inkl.md`](deployment-all-inkl.md).
