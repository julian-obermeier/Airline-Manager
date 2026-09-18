@extends('layouts.app')

@section('title', 'Airport Operations · Airline Empire')
@section('eyebrow', 'AIRPORT OPERATIONS')
@section('heading', 'Stationen & Slots')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">STATIONEN</span>
        <strong>{{ $stations->where('status', 'active')->count() }}</strong>
        <small>{{ $baseCount }} Base · {{ $outstationCount }} Outstations</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">STATIONSKOSTEN</span>
        <strong>{{ number_format($monthlyStationCostMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>monatliche Netzwerk-Fixkosten</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">KOMMENDE SLOTS</span>
        <strong>{{ $upcomingSlotCount }}</strong>
        <small>reservierte Abflug- und Ankunftsslots</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">SLOT-FENSTER</span>
        <strong>{{ config('airport_operations.slot_bucket_minutes', 15) }} min</strong>
        <small>Kapazität wird je Flughafen und Zeitfenster geprüft</small>
    </article>
</section>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">NETWORK DEVELOPMENT</span>
                <h3>Station eröffnen</h3>
            </div>
            <span class="badge">Outstation</span>
        </div>

        @if($airports->isEmpty())
            <div class="empty">An allen aktuell verfügbaren Flughäfen besteht bereits eine Station.</div>
        @else
            <form method="post" action="{{ route('airport-operations.stations.store') }}" class="form-grid">
                @csrf
                <div class="field full">
                    <label for="airport_id">Flughafen</label>
                    <select id="airport_id" name="airport_id" required>
                        <option value="">Bitte auswählen</option>
                        @foreach($airports as $airport)
                            <option value="{{ $airport->id }}" @selected(old('airport_id') === $airport->id)>
                                {{ $airport->iata_code }} / {{ $airport->icao_code }} · {{ $airport->city }} · {{ $airport->name }}
                            </option>
                        @endforeach
                    </select>
                    <span class="help">Für neue Routen werden fehlende Stationen automatisch eröffnet. Hier kannst du Stationen bereits vorher aufbauen.</span>
                </div>
                <div class="field full">
                    <button class="button primary" type="submit">Station verbindlich eröffnen</button>
                </div>
            </form>
        @endif
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">RULES</span>
                <h3>Airport-Logik</h3>
            </div>
            <span class="badge">Aktiv</span>
        </div>

        <div class="stack">
            <p><strong>Station:</strong> Jede von deiner Airline betriebene Route benötigt eine Station an beiden Flughäfen.</p>
            <p><strong>Slot:</strong> Jeder konkrete Flug reserviert genau einen Abflug- und einen Ankunftsslot.</p>
            <p><strong>Kapazität:</strong> Ist das Zeitfenster am Flughafen voll, wird der Flug nicht angelegt.</p>
            <p><strong>Kosten:</strong> Slots, Bewegungen und Passagierabfertigung werden beim Flugabschluss separat verbucht.</p>
        </div>
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">STATION NETWORK</span>
            <h3>Stationsnetz</h3>
        </div>
        <span class="badge">{{ $stations->count() }} Stationen</span>
    </div>

    @if($stations->isEmpty())
        <div class="empty">Noch keine Stationen vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Flughafen</th>
                    <th>Typ</th>
                    <th>Slotkapazität</th>
                    <th>Monatliche Kosten</th>
                    <th>Eröffnet</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($stations as $station)
                    <tr>
                        <td>
                            <strong>{{ $station->airport?->iata_code }} / {{ $station->airport?->icao_code }}</strong><br>
                            <span class="muted">{{ $station->airport?->city }} · {{ $station->airport?->name }}</span>
                        </td>
                        <td><span class="badge">{{ $station->station_type === 'base' ? 'Base' : 'Outstation' }}</span></td>
                        <td>{{ data_get($station->metadata, 'slot_capacity_per_bucket', config('airport_operations.default_slot_capacity_per_bucket', 12)) }} / {{ config('airport_operations.slot_bucket_minutes', 15) }} min</td>
                        <td>{{ number_format($station->monthly_cost_minor / 100, 2, ',', '.') }} {{ $station->currency }}</td>
                        <td>{{ $station->opened_at->timezone('Europe/Berlin')->format('d.m.Y') }}</td>
                        <td><span class="badge">{{ $station->status === 'active' ? 'Aktiv' : ucfirst($station->status) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">SLOT BOARD</span>
            <h3>Kommende Slot-Reservierungen</h3>
        </div>
        <span class="badge">{{ $slots->count() }} Bewegungen</span>
    </div>

    @if($slots->isEmpty())
        <div class="empty">Noch keine zukünftigen Slots reserviert.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Zeit</th>
                    <th>Flughafen</th>
                    <th>Bewegung</th>
                    <th>Flug</th>
                    <th>Route</th>
                    <th>Slot-Fenster</th>
                    <th>Gebühr</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($slots as $slot)
                    <tr>
                        <td>{{ $slot->scheduled_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td><strong>{{ $slot->airport?->iata_code }}</strong></td>
                        <td>{{ $slot->movement_type === 'departure' ? 'Abflug' : 'Ankunft' }}</td>
                        <td>{{ $slot->flight?->flight_number ?? '–' }}</td>
                        <td>{{ $slot->flight?->route?->origin?->iata_code }} → {{ $slot->flight?->route?->destination?->iata_code }}</td>
                        <td>{{ $slot->slot_key }}</td>
                        <td>{{ number_format($slot->fee_minor / 100, 2, ',', '.') }} {{ $slot->currency }}</td>
                        <td><span class="badge">{{ $slot->status === 'reserved' ? 'Reserviert' : 'Genutzt' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Slotkapazitäten und Gebühren sind Spielparameter der Airline-Empire-Simulation. Sie bilden keine offiziellen Flughafenentgelte oder reale Slot-Koordination ab.</p>
@endsection
