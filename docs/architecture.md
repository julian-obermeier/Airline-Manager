# Architektur – Airline Empire

## 1. Architekturziel

Airline Empire wird als modularer Monolith mit API-first-Schnittstelle entwickelt. Die Struktur soll eine spätere horizontale Skalierung ermöglichen, ohne frühzeitig unnötige Microservices einzuführen.

## 2. Laufzeitkomponenten

### Web/API
- Laravel 13
- REST-/JSON-API als primäre Anwendungsschnittstelle
- serverseitige Autorisierung und Validierung
- stateless-fähige API-Endpunkte, wo sinnvoll

### Frontend
- Vue 3 + TypeScript
- Vite
- responsive Desktop-artige Oberfläche
- PWA-fähige Struktur
- Dark/Light Mode als spätere UI-Basis

### Datenhaltung
- PostgreSQL als Source of Truth
- Redis für Cache, Queue, Sessions und kurzlebige Locks
- Object Storage für Logos, Lackierungen, Avatare und weitere Medien

### Hintergrundverarbeitung
- Queue Worker
- Laravel Scheduler
- Simulation Ticks / zeitgesteuerte Jobs
- Domain Events
- idempotente Jobs

### Realtime
- WebSocket-Schicht für Flugstatus, Notifications, Chat und OCC-Updates
- Architektur kompatibel mit Laravel Reverb

## 3. Domänengrenzen

Die Kernmodule werden fachlich getrennt gehalten:

- Identity
- Worlds
- Airlines
- Airports
- Aircraft
- Fleet
- Network
- Flights
- Finance
- Simulation
- Administration

Spätere Module:

- Crew
- Maintenance
- Ground Operations
- Revenue Management
- Cargo
- Alliances
- M&A
- Marketing
- Loyalty
- Safety
- Insurance
- Economy
- AI Airlines

## 4. Multi-World-Modell

Ein Benutzerkonto ist global. Spielstände sind weltbezogen.

Grundregel:

- globale Tabellen: Benutzerkonto, Account-Einstellungen, globale Achievements
- weltbezogene Tabellen: Airline, Flugzeuge, Routen, Flüge, Finanzen, Nachfrage, KI, Slots

Weltbezogene Datensätze erhalten konsequent eine `world_id` oder sind eindeutig über eine weltgebundene Parent-Entität ableitbar.

Es darf niemals möglich sein, Assets oder Geld versehentlich zwischen unabhängigen Spielwelten zu verwenden.

## 5. Simulation Engine

Die Simulation läuft ausschließlich serverseitig und unabhängig von eingeloggten Spielern.

### Anforderungen

- deterministische Eingaben führen bei gleichem Seed und Zustand zu nachvollziehbaren Resultaten
- transaktionale Zustandsänderungen
- idempotente Jobs
- Sperren gegen Doppelverarbeitung
- Auditierbarkeit wichtiger Änderungen
- klare Simulationszeit je Welt

### Geplante Verarbeitung

Jede Welt besitzt unter anderem:

- `current_simulation_time`
- `speed_multiplier`
- `simulation_status`
- `random_seed`
- `last_tick_at`

Zeitabhängige Systeme verwenden ausschließlich die jeweilige Weltzeit und nicht direkt die Browserzeit des Spielers.

## 6. Finanzmodell

Finanzen werden ledger-basiert aufgebaut.

Wichtige Prinzipien:

- jede Buchung besitzt Soll-/Haben-Logik bzw. eindeutig positive/negative Ledger-Zeilen
- kein mehrfaches Verbuchen eines externen Ereignisses
- externe Referenz / Idempotency Key je automatisierter Buchung
- Kontostände werden aus Buchungen abgeleitet oder kontrolliert materialisiert
- Geldwerte als exakte Decimal-/Integer-Minor-Unit-Werte, niemals Float

## 7. Flugzeugmodell

Jedes physische Flugzeug ist ein eigener Datensatz.

Trennung:

- `aircraft_types`: Stammdaten eines Musters
- `aircraft`: konkrete Maschine
- `aircraft_configs`: Kabinen-/Betriebskonfiguration
- `aircraft_ownerships`: Eigentum/Leasing
- `aircraft_status_history`: Statushistorie

Ein konkretes Flugzeug darf zu einem Zeitpunkt nicht in zwei überlappenden Umläufen eingesetzt werden.

## 8. Flugplanung

Flugplanung wird schrittweise aufgebaut:

1. Route zwischen zwei Flughäfen
2. Flugplanvorlage / Service Pattern
3. konkrete Fluginstanz
4. Flugzeugzuweisung
5. Rotation
6. Slot- und Crewprüfung

Zeitwerte werden intern UTC-normalisiert gespeichert. Flughafenzeitzonen dienen zur Darstellung und lokalen Regelprüfung.

## 9. Sicherheit

- CSRF-Schutz für browserbasierte Sessions
- XSS-Schutz durch Framework-Escaping und kontrollierte HTML-Ausgabe
- ORM/Query Builder statt ungeprüfter SQL-Konkatenation
- Rate Limiting
- 2FA vorbereiten
- Policies / Permissions serverseitig
- API Token nur für definierte Integrationen
- Audit Logs für administrative und kritische wirtschaftliche Aktionen

## 10. Skalierungsstrategie

Zunächst modularer Monolith.

Skalierung über:

- mehrere Web-Instanzen
- getrennte Queue Worker nach Queue-Klassen
- Redis
- PostgreSQL Read Replicas, falls später nötig
- Object Storage + CDN
- partitionierbare große Tabellen für Flüge, Events und Ledger

Erst wenn Lastprofile dies rechtfertigen, können besonders rechenintensive Systeme wie Nachfrageberechnung, KI oder Flight Simulation in getrennte Services ausgelagert werden.
