@extends('layouts.app')

@section('title', 'Weltkarte · Airline Empire')
@section('eyebrow', 'NETWORK CONTROL')
@section('heading', 'Weltkarte')
@section('subheading', $airline->name.' · '.$world->name.' · Weltzeit '.$simulationNow->timezone('Europe/Berlin')->format('d.m.Y H:i'))

@section('content')
@php
    $flightStatusLabels = [
        'boarding' => 'Boarding',
        'departed' => 'Abgeflogen',
        'in_air' => 'Unterwegs',
    ];
@endphp

<style>
    .world-map-wrap{position:relative;width:100%;overflow:hidden;border:1px solid rgba(32,52,78,.8);border-radius:18px;background:
        radial-gradient(circle at 22% 28%,rgba(57,184,255,.08),transparent 20rem),
        linear-gradient(180deg,#081827,#071321)}
    .world-map{display:block;width:100%;height:auto;min-height:440px}
    .map-grid{stroke:rgba(126,214,255,.08);stroke-width:1}
    .map-route{stroke:#39b8ff;stroke-width:3;stroke-linecap:round;opacity:.72}
    .map-route-glow{stroke:#39b8ff;stroke-width:10;stroke-linecap:round;opacity:.08}
    .map-airport{fill:#91a5bb;stroke:#071321;stroke-width:2}
    .map-airport.network{fill:#39b8ff}
    .map-airport.home{fill:#39d98a;stroke:#eafff5;stroke-width:3}
    .map-label{fill:#dcecff;font-size:17px;font-weight:800;paint-order:stroke;stroke:#071321;stroke-width:4px;stroke-linejoin:round}
    .map-plane{fill:#ffca5c;stroke:#fff0bd;stroke-width:2}
    .map-flight-label{fill:#fff4ca;font-size:15px;font-weight:800;paint-order:stroke;stroke:#071321;stroke-width:4px}
    .map-legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:14px;color:var(--muted);font-size:.82rem}
    .map-legend span{display:inline-flex;align-items:center;gap:7px}
    .legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;background:#91a5bb}
    .legend-dot.network{background:#39b8ff}.legend-dot.home{background:#39d98a}.legend-dot.flight{background:#ffca5c}
</style>

<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">AIRPORT DATABASE</span>
        <strong>{{ $mapAirports->count() }}</strong>
        <small>Flughäfen in der aktuellen Datenbank</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">NETWORK</span>
        <strong>{{ $mapRoutes->count() }}</strong>
        <small>aktive Strecken deiner Airline</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">LIVE TRAFFIC</span>
        <strong>{{ $mapFlights->count() }}</strong>
        <small>Boarding oder aktuell unterwegs</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">HOME BASE</span>
        <strong>{{ $mapAirports->firstWhere('home', true)['iata'] ?? '–' }}</strong>
        <small>{{ $mapAirports->firstWhere('home', true)['city'] ?? 'Keine Basis' }}</small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">LIVE NETWORK MAP</span>
            <h3>Streckennetz & Flugpositionen</h3>
        </div>
        <span class="badge">ohne externe Kartendienste</span>
    </div>

    <div class="world-map-wrap">
        <svg class="world-map" viewBox="0 0 1000 600" role="img" aria-label="Dynamische Karte des Airline-Streckennetzes">
            @for($x = 100; $x < 1000; $x += 100)
                <line class="map-grid" x1="{{ $x }}" y1="0" x2="{{ $x }}" y2="600" />
            @endfor
            @for($y = 100; $y < 600; $y += 100)
                <line class="map-grid" x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" />
            @endfor

            @foreach($mapRoutes as $route)
                <line class="map-route-glow" x1="{{ $route['origin']['x'] }}" y1="{{ $route['origin']['y'] }}" x2="{{ $route['destination']['x'] }}" y2="{{ $route['destination']['y'] }}" />
                <line class="map-route" x1="{{ $route['origin']['x'] }}" y1="{{ $route['origin']['y'] }}" x2="{{ $route['destination']['x'] }}" y2="{{ $route['destination']['y'] }}">
                    <title>{{ $route['origin']['iata'] }} → {{ $route['destination']['iata'] }} · {{ number_format($route['distance_km'], 0, ',', '.') }} km</title>
                </line>
            @endforeach

            @foreach($mapAirports as $airport)
                <circle class="map-airport {{ $airport['network'] ? 'network' : '' }} {{ $airport['home'] ? 'home' : '' }}" cx="{{ $airport['x'] }}" cy="{{ $airport['y'] }}" r="{{ $airport['home'] ? 9 : ($airport['network'] ? 7 : 4) }}">
                    <title>{{ $airport['iata'] }} · {{ $airport['city'] }} · {{ $airport['name'] }}</title>
                </circle>
                @if($airport['network'])
                    <text class="map-label" x="{{ $airport['x'] + 11 }}" y="{{ $airport['y'] - 10 }}">{{ $airport['iata'] }}</text>
                @endif
            @endforeach

            @foreach($mapFlights as $flight)
                <g>
                    <circle class="map-plane" cx="{{ $flight['x'] }}" cy="{{ $flight['y'] }}" r="8">
                        <title>{{ $flight['flight_number'] }} · {{ $flight['origin'] }} → {{ $flight['destination'] }} · {{ $flight['progress_percent'] }} %</title>
                    </circle>
                    <text class="map-flight-label" x="{{ $flight['x'] + 12 }}" y="{{ $flight['y'] + 5 }}">{{ $flight['flight_number'] }}</text>
                </g>
            @endforeach
        </svg>
    </div>

    <div class="map-legend">
        <span><i class="legend-dot home"></i> Heimatbasis</span>
        <span><i class="legend-dot network"></i> Netzwerk-Flughafen</span>
        <span><i class="legend-dot"></i> weiterer Flughafen</span>
        <span><i class="legend-dot flight"></i> aktueller Flug</span>
    </div>
</section>

<section class="grid grid-2" style="margin-top:18px">
    <article class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">ROUTE NETWORK</span>
                <h3>Aktive Strecken</h3>
            </div>
            <span class="badge">{{ $mapRoutes->count() }} Strecken</span>
        </div>
        @if($mapRoutes->isEmpty())
            <div class="empty">Noch keine Strecke vorhanden. Lege unter Operations deine erste Route an.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Route</th><th>Distanz</th><th>Blockzeit</th></tr></thead>
                    <tbody>
                    @foreach($mapRoutes as $route)
                        <tr>
                            <td><strong>{{ $route['origin']['iata'] }} → {{ $route['destination']['iata'] }}</strong><br><span class="muted">{{ $route['origin']['city'] }} → {{ $route['destination']['city'] }}</span></td>
                            <td>{{ number_format($route['distance_km'], 0, ',', '.') }} km</td>
                            <td>{{ intdiv($route['block_minutes'], 60) }}h {{ $route['block_minutes'] % 60 }}m</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>

    <article class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">LIVE FLIGHTS</span>
                <h3>Aktueller Verkehr</h3>
            </div>
            <span class="badge">{{ $mapFlights->count() }} Flüge</span>
        </div>
        @if($mapFlights->isEmpty())
            <div class="empty">Aktuell befindet sich kein Flug im Boarding oder in der Luft.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Flug</th><th>Route</th><th>Flugzeug</th><th>Fortschritt</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($mapFlights as $flight)
                        <tr>
                            <td><strong>{{ $flight['flight_number'] }}</strong></td>
                            <td>{{ $flight['origin'] }} → {{ $flight['destination'] }}</td>
                            <td>{{ $flight['aircraft'] ?? '–' }}</td>
                            <td>{{ $flight['progress_percent'] }} %</td>
                            <td><span class="badge">{{ $flightStatusLabels[$flight['status']] ?? ucfirst($flight['status']) }}{{ $flight['delay_minutes'] > 0 ? ' · +'.$flight['delay_minutes'].' min' : '' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>
</section>

<p class="footer-note">Die Karte wird ausschließlich aus den in Airline Empire gespeicherten Flughafenkoordinaten berechnet. Strecken und Flugpositionen stammen direkt aus deiner Spielwelt und benötigen keinen externen Kartenanbieter.</p>
@endsection
