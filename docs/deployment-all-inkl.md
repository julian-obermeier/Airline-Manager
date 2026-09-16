# Deployment auf ALL-INKL – airline.obermeier-it.de

## Ziel

- Domain: `https://airline.obermeier-it.de`
- Projektverzeichnis: `/www/htdocs/w021867a/airline.obermeier-it.de`
- Öffentlicher Document Root: `/www/htdocs/w021867a/airline.obermeier-it.de/public`
- Laufzeit: PHP 8.3+
- Datenbank: MySQL/MariaDB
- Cache: Datei
- Sessions: Datei
- Queue: synchron
- Broadcasting: Log

Das Shared-Hosting-Profil benötigt keinen Redis-Server, keinen Queue-Worker und keinen Reverb/WebSocket-Daemon.

## 1. KAS vorbereiten

1. Unter **Datenbanken** eine MySQL-/MariaDB-Datenbank anlegen.
2. Datenbank-Host, Datenbankname, Benutzername und Passwort notieren.
3. Für `airline.obermeier-it.de` PHP 8.3 oder neuer auswählen.
4. SSH für den Hauptaccount aktivieren, falls im Tarif vorhanden.
5. SSL/Let's Encrypt für die Subdomain aktivieren.
6. Die Domain erst nach dem Upload auf das Unterverzeichnis `airline.obermeier-it.de/public` zeigen lassen.

## 2. Empfohlene Deployment-Methode: GitHub-Artefakt

Der CI-Workflow erzeugt nach erfolgreichem Test das Artefakt `airline-manager-all-inkl`.

Dieses Paket enthält bereits:

- PHP-Produktionsabhängigkeiten unter `vendor/`
- gebaute Vue-/Vite-Assets unter `public/build/`
- Laravel-Anwendung
- Migrationen
- Produktionsvorlage `.env.all-inkl.example`

Node.js ist dadurch auf dem Webspace nicht erforderlich.

### Paket entpacken

Das heruntergeladene Archiv `airline-manager-all-inkl.tar.gz` in folgendes Verzeichnis übertragen:

```text
/www/htdocs/w021867a/airline.obermeier-it.de
```

Dann per SSH:

```bash
cd /www/htdocs/w021867a/airline.obermeier-it.de
tar -xzf airline-manager-all-inkl.tar.gz
rm airline-manager-all-inkl.tar.gz
```

## 3. Produktionsumgebung anlegen

```bash
cd /www/htdocs/w021867a/airline.obermeier-it.de
cp .env.all-inkl.example .env
```

Anschließend `.env` bearbeiten und mindestens diese Werte ersetzen:

```env
APP_URL=https://airline.obermeier-it.de

DB_HOST=DEIN_KAS_DATENBANK_HOST
DB_DATABASE=DEIN_KAS_DATENBANKNAME
DB_USERNAME=DEIN_KAS_DATENBANKBENUTZER
DB_PASSWORD=DEIN_KAS_DATENBANKPASSWORT
```

Die Datei `.env` darf niemals öffentlich oder in Git eingecheckt werden.

## 4. Laravel initialisieren

Wenn PHP 8.3 unter `/usr/bin/php83` verfügbar ist:

```bash
cd /www/htdocs/w021867a/airline.obermeier-it.de
/usr/bin/php83 artisan key:generate --force
/usr/bin/php83 artisan optimize:clear
/usr/bin/php83 artisan migrate --force
/usr/bin/php83 artisan config:cache
/usr/bin/php83 artisan route:cache
/usr/bin/php83 artisan view:cache
```

Alternativ kann `php` verwendet werden, wenn im SSH-Zugang bereits PHP 8.3+ als Standard-CLI-Version eingestellt wurde.

## 5. Domain auf `public/` stellen

Im KAS die Subdomain `airline.obermeier-it.de` bearbeiten.

Das Webspace-Ziel muss auf das Laravel-Public-Verzeichnis zeigen:

```text
/airline.obermeier-it.de/public
```

Das Projektstammverzeichnis darf **nicht** direkt öffentlich ausgeliefert werden.

## 6. Funktion prüfen

Nach Aktivierung von SSL und Document Root prüfen:

```text
https://airline.obermeier-it.de/up
https://airline.obermeier-it.de/api/v1/health
https://airline.obermeier-it.de/
```

Erwartung:

- `/up`: Laravel Health Check erfolgreich
- `/api/v1/health`: JSON-Systemstatus
- `/`: Vue-Oberfläche / Command Center

## 7. Spätere Updates

Bei jedem neuen Release:

1. Datenbank sichern.
2. Neues CI-Deployment-Artefakt einspielen.
3. Bestehende `.env` behalten.
4. Danach ausführen:

```bash
/usr/bin/php83 artisan optimize:clear
/usr/bin/php83 artisan migrate --force
/usr/bin/php83 artisan config:cache
/usr/bin/php83 artisan route:cache
/usr/bin/php83 artisan view:cache
```

Benutzerdaten in `storage/` und die produktive `.env` dürfen beim Update nicht überschrieben oder gelöscht werden.

## 8. Scheduler für spätere Simulationen

Sobald zeitgesteuerte Simulationen benötigt werden, wird auf ALL-INKL **kein** dauerhafter `schedule:work`-Prozess gestartet. Stattdessen wird ein KAS-Cronjob verwendet, der regelmäßig Folgendes ausführt:

```bash
/usr/bin/php83 /www/htdocs/w021867a/airline.obermeier-it.de/artisan schedule:run
```

Die eigentlichen Simulationsjobs müssen deshalb kurzlaufend, idempotent und cron-tauglich implementiert werden.

## Nicht auf Shared Hosting starten

Diese Befehle gehören erst zu einem späteren Managed-/dedizierten Serverbetrieb:

```bash
php artisan queue:work
php artisan reverb:start
php artisan schedule:work
```
