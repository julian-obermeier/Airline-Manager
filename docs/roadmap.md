# Entwicklungsroadmap

## Grundsatz

Die Entwicklung bleibt phasenweise. Ein Modul gilt erst als abgeschlossen, wenn Datenmodell, Migrationen, Backend, API, Frontend, Berechtigungen und Tests zusammen funktionieren.

## Phase 1 – Foundation

### 1. Identity & Authentication
- Registrierung
- Login/Logout
- E-Mail-Verifizierung
- Passwort-Reset
- globale Benutzerprofile
- Locale/Zeitzone
- Sicherheitsstatus
- 2FA-Vorbereitung

### 2. Worlds
- Persistent World
- Season World
- Private World
- Tutorial World
- Weltstatus
- Simulationsgeschwindigkeit
- Weltzeit
- Seed
- Aufnahme-/Teilnahmeverwaltung

### 3. Airline Creation
- Name
- ICAO/IATA/Callsign
- Logo/Branding-Metadaten
- Sitzland
- Heimatflughafen
- Geschäftsmodell
- Startkapital
- Servicekonzept
- Zielgruppe

### 4. Airport Database
- ICAO/IATA
- Name/Stadt/Land
- Koordinaten
- Zeitzone
- Elevation
- Runway-Basisdaten
- Kapazität
- Gebührenbasis
- Curfew/Nachtflug-Metadaten

### 5. Aircraft Database
- Hersteller
- Modell/Variante
- Reichweite
- Nutzlast
- Sitze
- Verbrauch
- Geschwindigkeit
- Mindestbahnlänge
- Kaufpreis
- Produktionsstatus

### 6. Basic Fleet
- konkrete Flugzeuge
- Registrierung
- Seriennummer
- Baujahr
- Standort
- Status
- Eigentum/Leasing-Grundmodell

### 7. Basic Routes
- Origin/Destination
- Distanz
- Blockzeit-Basis
- Route aktiv/inaktiv
- Airline-Zuordnung

### 8. Basic Flights
- Flugnummer
- Route
- geplanter Abflug/Ankunft
- konkretes Flugzeug
- Statusmodell
- serverseitige Zustandsübergänge

### 9. Basic Finance
- Kontenplan-Basis
- Ledger
- Startkapitalbuchung
- Flug-/Asset-Buchungsreferenzen
- Cash-Übersicht

### 10. Dashboard
- Airline KPIs
- Liquidität
- Flotte
- aktive/geplante Flüge
- Benachrichtigungen

### 11. World Map
- Flughäfen
- Airline-Netz
- Flugpositionen zunächst interpoliert aus Simulationsdaten
- Detailpanel für Flug/Airport

## Phase-1-Abnahmekriterien

Phase 1 ist abgeschlossen, wenn ein neuer Benutzer:

1. ein Konto erstellen kann,
2. einer Welt beitreten kann,
3. eine Airline gründen kann,
4. ein konkretes Flugzeug besitzen/leasen kann,
5. eine zulässige Route erstellen kann,
6. einen Flug planen kann,
7. diesen Flug serverseitig durch die Basiszustände simulieren kann,
8. finanzielle Auswirkungen nachvollziehbar im Ledger sieht,
9. Airline, Netzwerk und Flüge im Dashboard bzw. auf der Karte sehen kann.

Zusätzlich müssen kritische Domainregeln automatisiert getestet sein.

## Spätere Phasen

### Phase 2 – Airline Operations
Timetables, Rotations, Crew, Maintenance, Turnaround, Slots, Stations.

### Phase 3 – Economy
Passenger Demand, Revenue Management, Bookings, Competition, AI Airlines, Dynamic Economy.

### Phase 4 – Advanced Airline
Cargo, Alliances, Loyalty, Marketing, Infrastructure, Holding Companies.

### Phase 5 – Multiplayer Economy
Player Trading, Aircraft Market, Leasing, M&A, Joint Ventures, Rankings.

### Phase 6 – Hardcore Simulation
Weather, OCC, komplexe Maintenance, Safety, Insurance, Advanced Crew Rules.

### Phase 7 – Platform
Seasons, Premium, Community, Private Worlds, PWA/Mobile, Large-scale Optimization.
