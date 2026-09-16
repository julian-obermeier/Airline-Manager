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

## 4. Laravel aktivieren

Das Repository enthält für dieses Zielsystem ein Aktivierungsskript. Es erstellt die benötigten Runtime-Verzeichnisse, erzeugt bei Bedarf den Application Key, führt Migrationen aus und baut die Laravel-Caches.

```bash
cd /www/htdocs/w021867a/airline.obermeier-it.de
bash scripts/activate-all-inkl.sh
```

Das Skript verwendet standardmäßig:

```text
/usr/bin/php83
```

Falls auf dem Account ein anderer PHP-CLI-Pfad erforderlich ist:

```bash
PHP_BIN=/usr/bin/php84 bash scripts/activate-all-inkl.sh
```

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
4. Danach erneut ausführen:

```bash
bash scripts/activate-all-inkl.sh
```

Benutzerdaten in `storage/` und die produktive `.env` dürfen beim Update nicht überschrieben oder gelöscht werden.

## 8. Scheduler für spätere Simulationen

Sobald zeitgesteuerte Simulationen benötigt werden, wird auf ALL-INKL **kein** dauerhafter `schedule:work`-Prozess gestartet.

ALL-INKL-Cronjobs werden im KAS eingerichtet und müssen dort über eine HTTP(S)-aufrufbare Datei bzw. das von ALL-INKL dokumentierte Shellskript-Verfahren gestartet werden. Deshalb wird vor Einführung der Simulation Engine ein eigener geschützter Scheduler-Wrapper umgesetzt.

Die späteren Simulationsjobs müssen kurzlaufend, idempotent und cron-tauglich sein.

## Nicht auf Shared Hosting starten

Diese Befehle gehören erst zu einem späteren Managed-/dedizierten Serverbetrieb:

```bash
php artisan queue:work
php artisan reverb:start
php artisan schedule:work
```
