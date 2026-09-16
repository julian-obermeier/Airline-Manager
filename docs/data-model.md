# Phase-1-Datenmodell

## Leitlinien

- Benutzerkonten sind global.
- Spielwelten sind voneinander isoliert.
- Flughäfen und Flugzeugmuster sind globale Stammdaten.
- Airlines, konkrete Flugzeuge, Routen, Flüge und Finanzen sind weltbezogen.
- Fachliche Kernobjekte verwenden ULIDs.
- Zeitpunkte werden von der Anwendung UTC-normalisiert gespeichert und verarbeitet.
- Geld wird in Minor Units (z. B. Cent) als Integer gespeichert; keine Floats.
- Strukturierte Zusatzdaten verwenden portable JSON-Spalten, kompatibel mit MySQL/MariaDB.
- Das Schema vermeidet bewusst PostgreSQL-spezifische Typen, damit das ALL-INKL-Produktionsprofil unterstützt wird.

## Kernbeziehungen

```text
User
  └─< WorldMembership >─ World
                         └─< Airline >─ User (Owner)
                              ├─ home Airport
                              ├─< Aircraft >─ AircraftType
                              ├─< Route >─ Airport (origin/destination)
                              │    └─< Flight >─ Aircraft
                              └─< LedgerAccount
                                   └─< LedgerEntry >─ LedgerTransaction
```

## Global

### users
Globales Konto des Spielers.

### airports
Globale Flughafen-Stammdaten. Weltabhängige Nachfrage, Slots, Gebührenentwicklungen und Kapazitätszustände werden später in eigenen World-State-Tabellen modelliert.

### aircraft_types
Technische Stammdaten eines Flugzeugmusters. Konkrete Maschinen sind davon getrennt.

## Weltbezogen

### worlds
Enthält Typ, Status, Geschwindigkeitsfaktor, Simulationszeit und Seed.

### world_memberships
Verknüpft globale Accounts mit Spielwelten.

### airlines
Unternehmen innerhalb einer Welt. ICAO/IATA/Callsign werden pro Welt eindeutig behandelt.

### aircraft
Jede konkrete Maschine ist ein eigenes Asset mit Registrierung, Seriennummer, Standort und Betriebszustand.

### routes
Verbindet Origin und Destination für eine Airline.

### flights
Konkrete, zeitgebundene Fluginstanzen. Ein späteres Timetable-/Rotation-Modul wird darauf aufbauen.

## Finance

### ledger_accounts
Kontenplan einer Airline.

### ledger_transactions
Fachlicher Buchungsvorgang mit eindeutigem Idempotency Key.

### ledger_entries
Einzelne Soll-/Habenwirkung als vorzeichenbehafteter Minor-Unit-Betrag. Eine Transaktion muss in Summe 0 ergeben; diese Invariante wird im Domain Service transaktional geprüft.

## Spätere World-State-Tabellen

Nicht vorschnell in Phase 1 integrieren:

- `world_airport_states`
- `airport_slot_pools`
- `route_demand_snapshots`
- `market_share_snapshots`
- `fuel_price_snapshots`
- `economic_indicators`
- `aircraft_market_listings`
- `manufacturer_order_books`

Diese Tabellen werden erst mit den jeweiligen Simulationsmodulen eingeführt.
