# Airline Empire / Airline Manager

Professionelle browserbasierte Airline-, Wirtschafts- und Managementsimulation als Multiplayer-Webgame.

## Projektstatus

**Phase 1 – Foundation** wird aufgebaut.

Die Entwicklung erfolgt iterativ. Eine Phase wird erst erweitert, wenn Architektur, Datenmodell, Backend/API, Frontend, Berechtigungen und Tests des aktuellen Standes stabil sind.

## Aktueller Stack

- **Backend:** Laravel 13 / PHP 8.3+
- **Frontend:** Vue 3 + TypeScript
- **Build:** Vite 8
- **Primäre Datenbank:** MySQL/MariaDB
- **Shared-Hosting Cache:** Dateisystem
- **Shared-Hosting Sessions:** Dateisystem
- **Shared-Hosting Queue:** synchron
- **Shared-Hosting Broadcasting:** Log-Treiber
- **Optionale spätere Skalierung:** Redis, Queue Worker, Laravel Reverb/WebSockets
- **Architektur:** API-first, modularer Monolith mit klaren Domänengrenzen

## ALL-INKL Produktionsprofil

Das Projekt ist für `airline.obermeier-it.de` auf klassischem ALL-INKL-Webhosting vorbereitet.

Das Produktionsprofil benötigt **keinen Redis-Server, keinen dauerhaft laufenden Queue Worker und keinen Reverb/WebSocket-Daemon**. Diese Komponenten bleiben als spätere Skalierungsoption vorgesehen, wenn das Spiel auf einen Managed-/dedizierten Server umzieht.

Die Domain muss auf das Laravel-Verzeichnis `public/` zeigen.

Produktionsvorlage: [`.env.all-inkl.example`](.env.all-inkl.example)

Deployment-Anleitung: [`docs/deployment-all-inkl.md`](docs/deployment-all-inkl.md)

## Phase 1 – Foundation

Geplant und schrittweise umzusetzen:

1. Accounts und Authentication
2. Spielwelten
3. Airline-Gründung
4. Flughafen-Stammdaten
5. Flugzeug-Stammdaten
6. Basis-Flotte
7. Basis-Routen
8. Basis-Flüge
9. Basis-Finanzen
10. Dashboard
11. Weltkarte

## Architekturprinzipien

- Keine spielentscheidende Simulation im Browser.
- Serverseitige, reproduzierbare Simulation.
- Weltbezogene Daten werden strikt über `world_id` isoliert.
- Geldbewegungen werden als Ledger modelliert, nicht als frei veränderbarer Kontostand.
- Flugzeuge sind einzelne Assets und keine abstrakte Flottenzahl.
- Zustandsänderungen werden über Domain Events nachvollziehbar gemacht.
- Jobs müssen idempotent und transaktional sicher sein.
- Keine funktionslosen UI-Elemente oder Fake-Menüs.
- Zeitpunkte werden in der Anwendung UTC-normalisiert verarbeitet; Benutzer- und Flughafen-Zeitzonen dienen der Darstellung und Regelprüfung.

Weitere Details: [`docs/architecture.md`](docs/architecture.md) und [`docs/roadmap.md`](docs/roadmap.md).
