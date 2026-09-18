@extends('layouts.app')

@section('title', 'Revenue Management · Airline Empire')
@section('eyebrow', 'REVENUE MANAGEMENT')
@section('heading', 'Revenue Management')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">DYNAMISCHE ROUTEN</span>
        <strong>{{ $dynamicRoutes }}</strong>
        <small>von {{ $routes->count() }} aktiven Routen</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">DYNAMISCHE FLÜGE</span>
        <strong>{{ $changedFlights }}</strong>
        <small>kommende Flüge aktuell abweichend vom Basistarif</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">FARE EVENTS 24H</span>
        <strong>{{ $eventsToday }}</strong>
        <small>automatische Preisänderungen</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">BUCKETS</span>
        <strong>{{ count((array) config('revenue_management.load_buckets', [])) }}</strong>
        <small>Auslastungsstufen plus Abflugzeitfaktor</small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">BASE FARES</span>
            <h3>Basistarife pro Route</h3>
        </div>
        <span class="badge"><x-icon name="revenue" :size="14" /> Einzige Tarifverwaltung</span>
    </div>

    @if($routes->isEmpty())
        <div class="empty">Noch keine Route vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Route</th><th>Economy</th><th>Business</th><th>First</th><th>Aktion</th></tr></thead>
                <tbody>
                @foreach($routes as $route)
                    @php($fares = $routeData->get($route->id)['fares'])
                    <tr>
                        <td><strong>{{ $route->origin?->iata_code }} → {{ $route->destination?->iata_code }}</strong><br><span class="muted">{{ $route->origin?->city }} → {{ $route->destination?->city }}</span></td>
                        <td colspan="4">
                            <form method="post" action="{{ route('revenue-management.fares.update', $route) }}" style="display:grid;grid-template-columns:repeat(3,minmax(110px,1fr)) auto;gap:8px;align-items:end;min-width:520px">
                                @csrf
                                @method('PATCH')
                                <div class="field">
                                    <label>Economy</label>
                                    <input type="number" name="economy_fare" min="10" max="5000" step="0.01" value="{{ number_format($fares['economy_minor'] / 100, 2, '.', '') }}" required>
                                </div>
                                <div class="field">
                                    <label>Business</label>
                                    <input type="number" name="business_fare" min="0" max="10000" step="0.01" value="{{ number_format($fares['business_minor'] / 100, 2, '.', '') }}" required>
                                </div>
                                <div class="field">
                                    <label>First</label>
                                    <input type="number" name="first_fare" min="0" max="20000" step="0.01" value="{{ number_format($fares['first_minor'] / 100, 2, '.', '') }}" required>
                                </div>
                                <button class="button primary" type="submit">Basistarife speichern</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="footer-note">Neue Flüge übernehmen diese Werte als Basistarife. Bei dynamischer Policy verändert das Revenue Management anschließend nur die Verkaufspreise des konkreten Fluges.</p>
    @endif
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">ROUTE POLICIES</span>
            <h3>Preisstrategie pro Route</h3>
        </div>
        <span class="badge">Zentrale Preissteuerung</span>
    </div>

    @if($routes->isEmpty())
        <div class="empty">Noch keine Route vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Route</th>
                    <th>Basistarife</th>
                    <th>Modus</th>
                    <th>Strategie</th>
                    <th>Untergrenze</th>
                    <th>Obergrenze</th>
                    <th>Aktion</th>
                </tr>
                </thead>
                <tbody>
                @foreach($routes as $route)
                    @php
                        $pricing = $routeData->get($route->id);
                        $fares = $pricing['fares'];
                        $policy = $pricing['policy'];
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $route->origin?->iata_code }} → {{ $route->destination?->iata_code }}</strong><br>
                            <span class="muted">{{ number_format((float) $route->distance_km, 0, ',', '.') }} km</span>
                        </td>
                        <td>
                            E {{ number_format($fares['economy_minor'] / 100, 2, ',', '.') }} ·
                            B {{ number_format($fares['business_minor'] / 100, 2, ',', '.') }} ·
                            F {{ number_format($fares['first_minor'] / 100, 2, ',', '.') }}
                        </td>
                        <td colspan="5">
                            <form method="post" action="{{ route('revenue-management.policy.update', $route) }}" style="display:grid;grid-template-columns:minmax(120px,1fr) minmax(140px,1fr) 110px 110px auto;gap:8px;align-items:end;min-width:650px">
                                @csrf
                                @method('PATCH')
                                <div class="field">
                                    <label>Modus</label>
                                    <select name="mode">
                                        <option value="dynamic" @selected($policy['mode'] === 'dynamic')>Dynamisch</option>
                                        <option value="manual" @selected($policy['mode'] === 'manual')>Manuell</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Strategie</label>
                                    <select name="strategy">
                                        @foreach($strategies as $key => $strategy)
                                            <option value="{{ $key }}" @selected($policy['strategy'] === $key)>{{ $strategy['label'] ?? $key }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Min. %</label>
                                    <input type="number" name="floor_percent" min="40" max="100" value="{{ $policy['floor_percent'] }}" required>
                                </div>
                                <div class="field">
                                    <label>Max. %</label>
                                    <input type="number" name="ceiling_percent" min="100" max="350" value="{{ $policy['ceiling_percent'] }}" required>
                                </div>
                                <button class="button" type="submit">Policy speichern</button>
                            </form>
                        </td>
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
            <span class="eyebrow">LIVE INVENTORY</span>
            <h3>Aktuelle Flugpreise</h3>
        </div>
        <span class="badge">{{ $flights->count() }} kommende Flüge</span>
    </div>

    @if($flights->isEmpty())
        <div class="empty">Keine kommenden Flüge vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Flug</th>
                    <th>Route</th>
                    <th>Abflug</th>
                    <th>Economy</th>
                    <th>Business</th>
                    <th>First</th>
                    <th>Buchungsstand</th>
                    <th>Policy</th>
                    <th>Aktion</th>
                </tr>
                </thead>
                <tbody>
                @foreach($flights as $flight)
                    @php
                        $eBase = (int) data_get($flight->operational_data, 'commercial.cabins.economy.base_fare_minor', 0);
                        $eFare = (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor', 0);
                        $bBase = (int) data_get($flight->operational_data, 'commercial.cabins.business.base_fare_minor', 0);
                        $bFare = (int) data_get($flight->operational_data, 'commercial.cabins.business.fare_minor', 0);
                        $fBase = (int) data_get($flight->operational_data, 'commercial.cabins.first.base_fare_minor', 0);
                        $fFare = (int) data_get($flight->operational_data, 'commercial.cabins.first.fare_minor', 0);
                        $capacity = (int) data_get($flight->operational_data, 'seat_capacity',
                            ((int) data_get($flight->operational_data, 'commercial.cabins.economy.capacity', 0)) +
                            ((int) data_get($flight->operational_data, 'commercial.cabins.business.capacity', 0)) +
                            ((int) data_get($flight->operational_data, 'commercial.cabins.first.capacity', 0))
                        );
                        $policyMode = data_get($flight->operational_data, 'commercial.pricing.mode', 'dynamic');
                    @endphp
                    <tr>
                        <td><strong>{{ $flight->flight_number }}</strong></td>
                        <td>{{ $flight->route?->origin?->iata_code }} → {{ $flight->route?->destination?->iata_code }}</td>
                        <td>{{ $flight->scheduled_departure_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>
                            <strong>{{ number_format($eFare / 100, 2, ',', '.') }}</strong><br>
                            <span class="muted">Basis {{ number_format($eBase / 100, 2, ',', '.') }} · {{ data_get($flight->operational_data, 'commercial.cabins.economy.fare_bucket', 'initial') }}</span>
                        </td>
                        <td>
                            @if($bBase > 0)
                                <strong>{{ number_format($bFare / 100, 2, ',', '.') }}</strong><br>
                                <span class="muted">Basis {{ number_format($bBase / 100, 2, ',', '.') }} · {{ data_get($flight->operational_data, 'commercial.cabins.business.fare_bucket', 'initial') }}</span>
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                        <td>
                            @if($fBase > 0)
                                <strong>{{ number_format($fFare / 100, 2, ',', '.') }}</strong><br>
                                <span class="muted">Basis {{ number_format($fBase / 100, 2, ',', '.') }} · {{ data_get($flight->operational_data, 'commercial.cabins.first.fare_bucket', 'initial') }}</span>
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                        <td>{{ $flight->passengers_booked }} / {{ max(0, $capacity) }}</td>
                        <td><span class="badge">{{ $policyMode === 'dynamic' ? 'Dynamisch' : 'Manuell' }}</span></td>
                        <td>
                            @if($policyMode === 'dynamic')
                                <form method="post" action="{{ route('revenue-management.flights.reprice', $flight) }}">
                                    @csrf
                                    <button class="button ghost" type="submit">Jetzt kalkulieren</button>
                                </form>
                            @else
                                <span class="muted">Auto aus</span>
                            @endif
                        </td>
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
            <span class="eyebrow">FARE HISTORY</span>
            <h3>Preisänderungen</h3>
        </div>
        <span class="badge">{{ $events->count() }} letzte Events</span>
    </div>

    @if($events->isEmpty())
        <div class="empty">Noch keine dynamischen Preisänderungen vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Zeit</th>
                    <th>Flug</th>
                    <th>Route</th>
                    <th>Cabin</th>
                    <th>Bucket</th>
                    <th>Vorher</th>
                    <th>Neu</th>
                    <th>Load</th>
                    <th>Grund</th>
                </tr>
                </thead>
                <tbody>
                @foreach($events as $event)
                    <tr>
                        <td>{{ $event->calculated_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td><strong>{{ $event->flight?->flight_number ?? '–' }}</strong></td>
                        <td>{{ $event->flight?->route?->origin?->iata_code }} → {{ $event->flight?->route?->destination?->iata_code }}</td>
                        <td>{{ ucfirst($event->cabin) }}</td>
                        <td><span class="badge">{{ $event->bucket_code }}</span></td>
                        <td>{{ number_format($event->previous_fare_minor / 100, 2, ',', '.') }}</td>
                        <td><strong>{{ number_format($event->new_fare_minor / 100, 2, ',', '.') }}</strong></td>
                        <td>{{ number_format(((float) $event->load_factor) * 100, 1, ',', '.') }} %</td>
                        <td>{{ $event->reason }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Dynamische Tarife sind Spielwerte. Bereits verkaufte Sitze behalten ihren erzielten Umsatz; nur neue Buchungen werden zum jeweils aktuellen Tarif verkauft.</p>
@endsection
