@extends('layouts.app')

@section('title', 'Operations · Airline Empire')
@section('eyebrow', 'FLIGHT OPERATIONS')
@section('heading', 'Betriebszentrale')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong class="kpi-positive">{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Verfügbares Bankguthaben</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">FLOTTE</span>
        <strong>{{ $fleet->count() }}</strong>
        <small>Eigene Flugzeuge</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">ROUTEN</span>
        <strong>{{ $routes->count() }}</strong>
        <small>Aktive Strecken</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">FLUGPLAN</span>
        <strong>{{ $flights->count() }}</strong>
        <small>Geladene Fluginstanzen</small>
    </article>
</section>

<div class="grid grid-2 operations-forms" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">FLEET MARKET</span>
                <h3>Flugzeug kaufen</h3>
            </div>
            <span class="badge">Neuflugzeug</span>
        </div>

        @if($aircraftTypes->isEmpty())
            <div class="empty">Aktuell sind keine Flugzeugmuster im Markt verfügbar.</div>
        @else
            <form method="post" action="{{ route('operations.fleet.purchase') }}" class="form-grid">
                @csrf
                <div class="field full">
                    <label for="aircraft_type_id">Flugzeugmuster</label>
                    <select id="aircraft_type_id" name="aircraft_type_id" required>
                        <option value="">Bitte auswählen</option>
                        @foreach($aircraftTypes as $type)
                            <option value="{{ $type->id }}" @selected(old('aircraft_type_id') === $type->id)>
                                {{ $type->manufacturer }} {{ $type->model }} {{ $type->variant }} · {{ $type->typical_seats ?? '–' }} Sitze · {{ number_format(($type->reference_purchase_price_minor ?? 0) / 100, 0, ',', '.') }} {{ $type->reference_currency }}
                            </option>
                        @endforeach
                    </select>
                    <span class="help">Der Kaufpreis wird sofort aus dem Bankguthaben bezahlt und im Ledger verbucht.</span>
                </div>
                <div class="field full">
                    <label for="registration">Kennzeichen <span class="muted">(optional)</span></label>
                    <input id="registration" name="registration" value="{{ old('registration') }}" maxlength="16" placeholder="Wird automatisch erzeugt">
                </div>
                <div class="field full">
                    <button class="button primary" type="submit">Flugzeug verbindlich kaufen</button>
                </div>
            </form>
        @endif
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">NETWORK</span>
                <h3>Route anlegen</h3>
            </div>
            <span class="badge">Great Circle</span>
        </div>

        <form method="post" action="{{ route('operations.routes.store') }}" class="form-grid">
            @csrf
            <div class="field full">
                <label for="origin_airport_id">Startflughafen</label>
                <select id="origin_airport_id" name="origin_airport_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($airports as $airport)
                        <option value="{{ $airport->id }}" @selected(old('origin_airport_id', $airline->home_airport_id) === $airport->id)>
                            {{ $airport->iata_code }} / {{ $airport->icao_code }} · {{ $airport->city }} · {{ $airport->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field full">
                <label for="destination_airport_id">Zielflughafen</label>
                <select id="destination_airport_id" name="destination_airport_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($airports as $airport)
                        <option value="{{ $airport->id }}" @selected(old('destination_airport_id') === $airport->id)>
                            {{ $airport->iata_code }} / {{ $airport->icao_code }} · {{ $airport->city }} · {{ $airport->name }}
                        </option>
                    @endforeach
                </select>
                <span class="help">Distanz und geplante Blockzeit werden automatisch berechnet.</span>
            </div>
            <div class="field full">
                <button class="button primary" type="submit">Route anlegen</button>
            </div>
        </form>
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">FLIGHT SCHEDULING</span>
            <h3>Konkreten Flug planen</h3>
        </div>
        <span class="badge">Serverseitig validiert</span>
    </div>

    @if($routes->isEmpty() || $fleet->isEmpty())
        <div class="empty">Für die Flugplanung benötigst du mindestens ein Flugzeug und eine Route.</div>
    @else
        <form method="post" action="{{ route('operations.flights.store') }}" class="form-grid form-grid-4">
            @csrf
            <div class="field">
                <label for="route_id">Route</label>
                <select id="route_id" name="route_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($routes as $route)
                        <option value="{{ $route->id }}" @selected(old('route_id') === $route->id)>
                            {{ $route->origin->iata_code }} → {{ $route->destination->iata_code }} · {{ number_format((float) $route->distance_km, 0, ',', '.') }} km
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="aircraft_id">Flugzeug</label>
                <select id="aircraft_id" name="aircraft_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($fleet as $aircraft)
                        <option value="{{ $aircraft->id }}" @selected(old('aircraft_id') === $aircraft->id)>
                            {{ $aircraft->registration }} · {{ $aircraft->type->manufacturer }} {{ $aircraft->type->model }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="flight_number">Flugnummer</label>
                <input id="flight_number" name="flight_number" value="{{ old('flight_number', $airline->iata_code ? $airline->iata_code.'101' : '') }}" maxlength="12" required placeholder="z. B. AE101">
            </div>
            <div class="field">
                <label for="scheduled_departure_at">Abflug</label>
                <input id="scheduled_departure_at" type="datetime-local" name="scheduled_departure_at" value="{{ old('scheduled_departure_at') }}" required>
            </div>
            <div class="field full">
                <button class="button primary" type="submit">Flug in den Flugplan übernehmen</button>
            </div>
        </form>
    @endif
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">FLEET</span>
            <h3>Aktuelle Flotte</h3>
        </div>
        <span class="badge">{{ $fleet->count() }} Flugzeuge</span>
    </div>

    @if($fleet->isEmpty())
        <div class="empty">Deine Airline besitzt noch kein Flugzeug.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Kennzeichen</th><th>Muster</th><th>Standort</th><th>Reichweite</th><th>Zustand</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($fleet as $aircraft)
                    <tr>
                        <td><strong>{{ $aircraft->registration }}</strong></td>
                        <td>{{ $aircraft->type->manufacturer }} {{ $aircraft->type->model }}</td>
                        <td>{{ $aircraft->currentAirport?->iata_code ?? '–' }}</td>
                        <td>{{ $aircraft->type->range_km ? number_format($aircraft->type->range_km, 0, ',', '.').' km' : '–' }}</td>
                        <td>{{ number_format((float) $aircraft->condition_percent, 1, ',', '.') }} %</td>
                        <td><span class="badge">{{ $aircraft->status }}</span></td>
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
            <span class="eyebrow">NETWORK</span>
            <h3>Streckennetz</h3>
        </div>
        <span class="badge">{{ $routes->count() }} Routen</span>
    </div>

    @if($routes->isEmpty())
        <div class="empty">Noch keine Route vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Route</th><th>Distanz</th><th>Blockzeit</th><th>Flüge</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($routes as $route)
                    <tr>
                        <td><strong>{{ $route->origin->iata_code }} → {{ $route->destination->iata_code }}</strong><br><span class="muted">{{ $route->origin->city }} → {{ $route->destination->city }}</span></td>
                        <td>{{ number_format((float) $route->distance_km, 0, ',', '.') }} km</td>
                        <td>{{ intdiv($route->planned_block_minutes, 60) }}h {{ $route->planned_block_minutes % 60 }}m</td>
                        <td>{{ $route->flights_count }}</td>
                        <td><span class="badge">{{ $route->status }}</span></td>
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
            <span class="eyebrow">SCHEDULE</span>
            <h3>Flugplan</h3>
        </div>
        <span class="badge">Nächste {{ $flights->count() }}</span>
    </div>

    @if($flights->isEmpty())
        <div class="empty">Noch keine Flüge geplant.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Flug</th><th>Route</th><th>Flugzeug</th><th>Abflug</th><th>Ankunft</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($flights as $flight)
                    <tr>
                        <td><strong>{{ $flight->flight_number }}</strong></td>
                        <td>{{ $flight->route->origin->iata_code }} → {{ $flight->route->destination->iata_code }}</td>
                        <td>{{ $flight->aircraft?->registration ?? '–' }}</td>
                        <td>{{ $flight->scheduled_departure_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>{{ $flight->scheduled_arrival_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td><span class="badge">{{ $flight->status }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Flugzeugkäufe sind echte Ledger-Buchungen. Routen und Flüge werden ausschließlich innerhalb deiner aktiven Spielwelt und Airline angelegt.</p>
@endsection
