@extends('layouts.app')

@section('title', 'Operations · Airline Empire')
@section('eyebrow', 'FLIGHT OPERATIONS')
@section('heading', 'Betriebszentrale')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $aircraftStatusLabels = [
        'available' => 'Verfügbar',
        'in_flight' => 'Im Flug',
        'maintenance' => 'Wartung',
        'grounded' => 'Gesperrt',
    ];
    $flightStatusLabels = [
        'scheduled' => 'Geplant',
        'boarding' => 'Boarding',
        'departed' => 'Abgeflogen',
        'in_air' => 'Unterwegs',
        'completed' => 'Gelandet',
        'cancelled' => 'Annulliert',
    ];
@endphp

<section class="grid grid-4">
    <article class="card metric">
        <div class="metric-icon"><x-icon name="cash" :size="19" /></div>
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong class="kpi-positive">{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Verfügbares Bankguthaben</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="fleet" :size="19" /></div>
        <span class="eyebrow">FLOTTE</span>
        <strong>{{ $fleet->count() }}</strong>
        <small>Aktive Flugzeuge</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="route" :size="19" /></div>
        <span class="eyebrow">ROUTEN</span>
        <strong>{{ $routes->count() }}</strong>
        <small>Aktive Strecken</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="status" :size="19" /></div>
        <span class="eyebrow">SIMULATION</span>
        <strong>{{ $world->last_simulation_tick_at ? 'Aktiv' : 'Bereit' }}</strong>
        <small>
            {{ $world->last_simulation_tick_at
                ? 'Letzter Tick '.$world->last_simulation_tick_at->timezone('Europe/Berlin')->format('d.m. H:i')
                : 'Cronjob noch nicht ausgeführt' }}
        </small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">QUICK ACTIONS</span>
            <h3>Weiterführende Bereiche</h3>
        </div>
        <span class="badge">Keine Doppelverwaltung</span>
    </div>

    <div class="quick-grid">
        <a class="quick-link" href="{{ route('fleet-market.index') }}">
            <span class="quick-link-icon"><x-icon name="fleet" /></span>
            <div><strong>Flottenmarkt</strong><span>Kauf, Leasing und Gebrauchtflugzeuge</span></div>
        </a>
        <a class="quick-link" href="{{ route('revenue-management.index') }}">
            <span class="quick-link-icon"><x-icon name="revenue" /></span>
            <div><strong>Revenue Management</strong><span>Basistarife und dynamische Preise</span></div>
        </a>
        <a class="quick-link" href="{{ route('schedules.index') }}">
            <span class="quick-link-icon"><x-icon name="schedule" /></span>
            <div><strong>Flugpläne</strong><span>Wiederkehrende Umläufe verwalten</span></div>
        </a>
    </div>
</section>

<div class="grid grid-2 operations-forms" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">NETWORK</span>
                <h3>Route anlegen</h3>
            </div>
            <span class="badge"><x-icon name="route" :size="14" /> Netzwerk</span>
        </div>

        <form method="post" action="{{ route('operations.routes.store') }}" class="form-grid">
            @csrf
            <div class="field full">
                <label for="origin_airport_id">Startflughafen</label>
                <select id="origin_airport_id" name="origin_airport_id" data-searchable data-search-placeholder="Startflughafen suchen…" required>
                    <option value="">Bitte auswählen</option>
                    <x-airport-options :airports="$airports" :selected="old('origin_airport_id', $airline->home_airport_id)" />
                </select>
            </div>
            <div class="field full">
                <label for="destination_airport_id">Zielflughafen</label>
                <select id="destination_airport_id" name="destination_airport_id" data-searchable data-search-placeholder="Zielflughafen suchen…" required>
                    <option value="">Bitte auswählen</option>
                    <x-airport-options :airports="$airports" :selected="old('destination_airport_id')" />
                </select>
                <span class="help">Distanz, Blockzeit, Stationsbedarf und Nachfrage werden automatisch berechnet.</span>
            </div>
            <div class="field full">
                <button class="button primary" type="submit"><x-icon name="route" :size="17" /> Route anlegen</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">FLIGHT SCHEDULING</span>
                <h3>Einzelflug planen</h3>
            </div>
            <span class="badge"><x-icon name="plane" :size="14" /> Standortprüfung</span>
        </div>

        @if($routes->isEmpty() || $fleet->isEmpty())
            <div class="empty">Für einen Einzelflug benötigst du mindestens ein Flugzeug und eine Route.</div>
        @else
            <form method="post" action="{{ route('operations.flights.store') }}" class="form-grid">
                @csrf
                <div class="field full">
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
                    <select id="aircraft_id" name="aircraft_id" data-searchable data-search-placeholder="Flugzeug nach Kennzeichen oder Typ suchen…" required>
                        <option value="">Bitte auswählen</option>
                        @foreach($fleet as $aircraft)
                            <option value="{{ $aircraft->id }}" @selected(old('aircraft_id') === $aircraft->id)>
                                {{ $aircraft->registration }} · {{ $aircraft->currentAirport?->iata_code ?? 'Unterwegs' }} · {{ $aircraft->type->model }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="flight_number">Flugnummer</label>
                    <input id="flight_number" name="flight_number" value="{{ old('flight_number', $airline->iata_code ? $airline->iata_code.'101' : '') }}" maxlength="12" required>
                </div>
                <div class="field full">
                    <label for="scheduled_departure_at">Abflug</label>
                    <input id="scheduled_departure_at" type="datetime-local" name="scheduled_departure_at" value="{{ old('scheduled_departure_at') }}" required>
                </div>
                <div class="field full">
                    <button class="button primary" type="submit"><x-icon name="plane" :size="17" /> Flug einplanen</button>
                </div>
            </form>
        @endif
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">FLEET STATUS</span>
            <h3>Aktuelle Flotte</h3>
        </div>
        <div class="split-actions">
            <span class="badge">{{ $fleet->count() }} Flugzeuge</span>
            <a class="button ghost" href="{{ route('fleet-market.index') }}">Flotte erweitern <x-icon name="arrow" :size="15" /></a>
        </div>
    </div>

    @if($fleet->isEmpty())
        <div class="empty">Noch kein Flugzeug vorhanden. Beschaffung erfolgt zentral im Flottenmarkt.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Kennzeichen</th><th>Muster</th><th>Standort</th><th>Flugstunden</th><th>Zyklen</th><th>Zustand</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($fleet as $aircraft)
                    <tr>
                        <td><strong>{{ $aircraft->registration }}</strong></td>
                        <td>{{ $aircraft->type->manufacturer }} {{ $aircraft->type->model }}</td>
                        <td><strong>{{ $aircraft->currentAirport?->iata_code ?? ($aircraft->status === 'in_flight' ? 'Unterwegs' : '–') }}</strong></td>
                        <td>{{ number_format((float) $aircraft->flight_hours, 1, ',', '.') }} h</td>
                        <td>{{ number_format((int) $aircraft->flight_cycles, 0, ',', '.') }}</td>
                        <td>{{ number_format((float) $aircraft->condition_percent, 1, ',', '.') }} %</td>
                        <td><span class="badge">{{ $aircraftStatusLabels[$aircraft->status] ?? ucfirst(str_replace('_', ' ', $aircraft->status)) }}</span></td>
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
            <span class="eyebrow">NETWORK STATUS</span>
            <h3>Streckennetz</h3>
        </div>
        <div class="split-actions">
            <span class="badge">{{ $routes->count() }} Routen</span>
            <a class="button ghost" href="{{ route('revenue-management.index') }}">Tarife verwalten <x-icon name="arrow" :size="15" /></a>
        </div>
    </div>

    @if($routes->isEmpty())
        <div class="empty">Noch keine Route vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Route</th><th>Distanz</th><th>Blockzeit</th><th>Nachfrageindex</th><th>Flüge</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($routes as $route)
                    <tr>
                        <td>
                            <strong>{{ $route->origin->iata_code }} → {{ $route->destination->iata_code }}</strong><br>
                            <span class="muted">{{ $route->origin->city }} → {{ $route->destination->city }}</span>
                        </td>
                        <td>{{ number_format((float) $route->distance_km, 0, ',', '.') }} km</td>
                        <td>{{ intdiv($route->planned_block_minutes, 60) }}h {{ $route->planned_block_minutes % 60 }}m</td>
                        <td><span class="badge">{{ number_format((float) data_get($route->settings, 'demand_index', 1), 2, ',', '.') }}</span></td>
                        <td>{{ $route->flights_count }}</td>
                        <td><span class="badge">{{ $route->status === 'active' ? 'Aktiv' : ucfirst($route->status) }}</span></td>
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
            <span class="eyebrow">LIVE SCHEDULE</span>
            <h3>Flugplan & Betrieb</h3>
        </div>
        <span class="badge">{{ $flights->count() }} Flüge</span>
    </div>

    @if($flights->isEmpty())
        <div class="empty">Noch keine Flüge geplant.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Flug</th><th>Route</th><th>Flugzeug</th><th>Abflug</th><th>Ankunft</th><th>Crew</th><th>Buchungen</th><th>Umsatz</th><th>Ergebnis</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($flights as $flight)
                    @php
                        $economyBooked = (int) data_get($flight->operational_data, 'commercial.cabins.economy.booked', 0);
                        $businessBooked = (int) data_get($flight->operational_data, 'commercial.cabins.business.booked', 0);
                        $firstBooked = (int) data_get($flight->operational_data, 'commercial.cabins.first.booked', 0);
                        $bookingValueMinor =
                            ((int) data_get($flight->operational_data, 'commercial.cabins.economy.revenue_minor', 0)) +
                            ((int) data_get($flight->operational_data, 'commercial.cabins.business.revenue_minor', 0)) +
                            ((int) data_get($flight->operational_data, 'commercial.cabins.first.revenue_minor', 0));
                        $profitMinor = data_get($flight->operational_data, 'economics.profit_minor');
                        $bookingProgress = (float) data_get($flight->operational_data, 'commercial.booking_progress', 0);
                        $crewSnapshot = $crewSnapshots->get($flight->id);
                    @endphp
                    <tr>
                        <td><strong>{{ $flight->flight_number }}</strong></td>
                        <td>{{ $flight->route->origin->iata_code }} → {{ $flight->route->destination->iata_code }}</td>
                        <td>{{ $flight->aircraft?->registration ?? '–' }}</td>
                        <td>{{ $flight->scheduled_departure_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>{{ $flight->scheduled_arrival_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>
                            @if($crewSnapshot && $crewSnapshot['complete'])
                                <span class="badge">{{ $crewSnapshot['assigned']['total'] }} / {{ $crewSnapshot['requirements']['total'] }}</span>
                            @elseif($crewSnapshot)
                                <span class="badge">Fehlen {{ $crewSnapshot['missing']['total'] }}</span>
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $flight->passengers_booked }}</strong>
                            @if($economyBooked + $businessBooked + $firstBooked > 0)
                                <br><span class="muted">E {{ $economyBooked }} · B {{ $businessBooked }} · F {{ $firstBooked }}</span>
                            @endif
                            @if($bookingProgress > 0 && !in_array($flight->status, ['completed', 'cancelled'], true))
                                <br><span class="muted">{{ number_format($bookingProgress * 100, 0, ',', '.') }} % Buchungsphase</span>
                            @endif
                        </td>
                        <td>{{ $bookingValueMinor > 0 ? number_format($bookingValueMinor / 100, 2, ',', '.').' '.$airline->base_currency : '–' }}</td>
                        <td>
                            @if($profitMinor !== null)
                                <strong class="{{ $profitMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($profitMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                        <td><span class="badge">{{ $flightStatusLabels[$flight->status] ?? ucfirst(str_replace('_', ' ', $flight->status)) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
