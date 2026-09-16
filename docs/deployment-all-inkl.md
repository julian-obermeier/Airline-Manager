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
6. Die Domain auf das Unterverzeichnis `airline.obermeier-it.de/public` zeigen lassen.

## 2. Deployment aus `main`

```bash
cd /www/htdocs/w021867a/airline.obermeier-it.de
git pull origin main
bash scripts/activate-all-inkl.sh
```

Beim ersten Setup kann das Repository direkt in das leere Zielverzeichnis geklont werden. Die produktive `.env` bleibt bei späteren Updates erhalten.

## 3. Produktionsumgebung

Falls noch keine `.env` vorhanden ist:

```bash
cp .env.all-inkl.example .env
```

Anschließend mindestens diese Werte setzen:

```env
APP_URL=https://airline.obermeier-it.de

DB_HOST=DEIN_KAS_DATENBANK_HOST
DB_DATABASE=DEIN_KAS_DATENBANKNAME
DB_USERNAME=DEIN_KAS_DATENBANKBENUTZER
DB_PASSWORD=DEIN_KAS_DATENBANKPASSWORT
```

Die Datei `.env` darf niemals öffentlich oder in Git eingecheckt werden.

## 4. Laravel aktivieren

```bash
bash scripts/activate-all-inkl.sh
```

Das Skript:

- installiert fehlende Composer-Produktionsabhängigkeiten,
- erzeugt bei Bedarf den Application Key,
- erzeugt bei Bedarf einen 64-stelligen Simulation-Cron-Token,
- führt Migrationen aus,
- aktualisiert die Spielstammdaten,
- baut Laravel-Caches,
- gibt am Ende die geschützte Cron-URL aus.

Standardmäßig wird `/usr/bin/php83` verwendet. Bei Bedarf:

```bash
PHP_BIN=/usr/bin/php84 bash scripts/activate-all-inkl.sh
```

## 5. Domain auf `public/` stellen

Im KAS muss `airline.obermeier-it.de` auf folgendes Webspace-Ziel zeigen:

```text
/airline.obermeier-it.de/public
```

Das Projektstammverzeichnis darf **nicht** direkt öffentlich ausgeliefert werden.

## 6. Funktion prüfen

```text
https://airline.obermeier-it.de/up
https://airline.obermeier-it.de/api/v1/health
https://airline.obermeier-it.de/
```

Erwartung:

- `/up`: Laravel Health Check erfolgreich
- `/api/v1/health`: JSON-Systemstatus
- `/`: Login bzw. Airline-Command-Center

## 7. Simulation-Cron aktivieren

Airline Empire verwendet auf Shared Hosting keinen dauerhaften Worker. Die Simulation Engine wird über einen kurzen, idempotenten HTTP-Cron-Tick ausgeführt.

Nach `bash scripts/activate-all-inkl.sh` wird eine URL dieser Form ausgegeben:

```text
https://airline.obermeier-it.de/system/cron/simulate?token=DEIN_GENERIERTER_TOKEN
```

Im ALL-INKL KAS unter **Tools → Cronjobs** einen neuen Cronjob anlegen und genau diese URL als Protokoll/Pfad eintragen. Als Ausführungsintervall werden für den aktuellen Stand **5 Minuten** empfohlen.

Der Endpoint ist ohne den in `.env` gespeicherten `SIMULATION_CRON_TOKEN` nicht aufrufbar. Der Token darf nicht veröffentlicht oder in Git eingecheckt werden.

Die Simulation verarbeitet pro Tick unter anderem:

- Weltzeit und Weltgeschwindigkeit,
- Boarding,
- Abflug,
- Reiseflug,
- Landung,
- Passagierauslastung,
- Flugumsätze,
- Treibstoffkosten,
- operative Kosten,
- Flugzeugposition,
- Flugstunden und Flugzyklen,
- technischen Zustandsverschleiß.

Der Flugabschluss ist idempotent: wiederholte Cron-Aufrufe erzeugen keine doppelten Ledger-Buchungen.

## 8. Manuelle Simulation per SSH

Für Tests kann ein Tick auch direkt ausgeführt werden:

```bash
/usr/bin/php83 artisan airline:simulate
```

## 9. Spätere Updates

Bei jedem neuen Release:

```bash
cd /www/htdocs/w021867a/airline.obermeier-it.de
git pull origin main
bash scripts/activate-all-inkl.sh
```

Die bestehende `.env` und produktive Benutzerdaten bleiben erhalten.

## Nicht auf Shared Hosting starten

Diese Befehle gehören erst zu einem späteren Managed-/dedizierten Serverbetrieb:

```bash
php artisan queue:work
php artisan reverb:start
php artisan schedule:work
```
