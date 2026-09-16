# Airline Empire / Airline Manager

Professionelle browserbasierte Airline-, Wirtschafts- und Managementsimulation als Multiplayer-Webgame.

## Projektstatus

**Phase 1 – Foundation** wird aufgebaut.

Die Entwicklung erfolgt iterativ. Eine Phase wird erst erweitert, wenn Architektur, Datenmodell, Backend/API, Frontend, Berechtigungen und Tests des aktuellen Standes stabil sind.

## Zielstack

- **Backend:** Laravel 13 / PHP 8.3+
- **Frontend:** Vue 3 + TypeScript
- **Build:** Vite 8
- **Datenbank:** PostgreSQL
- **Cache / Queues / Sessions:** Redis
- **Realtime:** WebSockets / Laravel Reverb-kompatible Architektur
- **Architektur:** API-first, modularer Monolith mit klaren Domänengrenzen
- **Betrieb:** Queue Worker, Scheduler, Health Checks, Monitoring, CI/CD, Backups

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
- Server-seitige, reproduzierbare Simulation.
- Weltbezogene Daten werden strikt über `world_id` isoliert.
- Geldbewegungen werden als Ledger modelliert, nicht als frei veränderbarer Kontostand.
- Flugzeuge sind einzelne Assets und keine abstrakte Flottenzahl.
- Zustandsänderungen werden über Domain Events nachvollziehbar gemacht.
- Jobs müssen idempotent und transaktional sicher sein.
- Keine funktionslosen UI-Elemente oder Fake-Menüs.

Weitere Details: [`docs/architecture.md`](docs/architecture.md) und [`docs/roadmap.md`](docs/roadmap.md).

## Lokale Entwicklung

Der vollständige Laravel-/Vue-Anwendungsbootstrap folgt als nächster Foundation-Schritt. Die Versions- und Architekturentscheidungen sind bereits in den Projektdateien fixiert, damit die Implementierung konsistent erfolgt.
