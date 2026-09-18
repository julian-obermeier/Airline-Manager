@extends('layouts.app')

@section('title', 'Weltkarte · Airline Empire')
@section('eyebrow', 'NETWORK CONTROL')
@section('heading', 'Weltkarte')
@section('subheading', $airline->name.' · '.$world->name.' · Weltzeit '.$simulationNow->timezone('Europe/Berlin')->format('d.m.Y H:i'))

@push('head')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIINfQ3ynWzRRCgEzQxYY3yEOi4fP4YVQyM=" crossorigin="">
<style>
    .leaflet-container{font:inherit;background:#dfeaf3}
    .airport-marker{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#fff;border:2px solid #94a3b8;color:#475569;font-size:9px;font-weight:900;box-shadow:0 4px 12px rgba(15,23,42,.18)}
    .airport-marker.network{width:34px;height:34px;border-color:#2563eb;color:#1d4ed8;background:#eff6ff;font-size:10px}
    .airport-marker.home{width:38px;height:38px;border-color:#059669;color:#047857;background:#ecfdf5}
    .flight-marker{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#fff7ed;border:2px solid #f59e0b;color:#c2410c;box-shadow:0 5px 15px rgba(194,65,12,.18);font-size:18px;transform:rotate(18deg)}
    .map-popup-title{font-weight:850;margin-bottom:3px}.map-popup-meta{color:#64748b;font-size:12px}
    .leaflet-popup-content-wrapper{border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.14)}
</style>
@endpush

@section('content')
@php
    $flightStatusLabels = [
        'boarding' => 'Boarding',
        'departed' => 'Abgeflogen',
        'in_air' => 'Unterwegs',
    ];
@endphp

<section class="grid grid-4">
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="airport" :size="19" /></div>
        <span class="eyebrow">AIRPORT DATABASE</span>
        <strong>{{ $mapAirports->count() }}</strong>
        <small>reale Flughäfen im Spiel</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="route" :size="19" /></div>
        <span class="eyebrow">NETWORK</span>
        <strong>{{ $mapRoutes->count() }}</strong>
        <small>aktive Routen deiner Airline</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="plane" :size="19" /></div>
        <span class="eyebrow">LIVE TRAFFIC</span>
        <strong>{{ $mapFlights->count() }}</strong>
        <small>Boarding oder aktuell unterwegs</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="map" :size="19" /></div>
        <span class="eyebrow">HOME BASE</span>
        <strong>{{ $mapAirports->firstWhere('home', true)['iata'] ?? '–' }}</strong>
        <small>{{ $mapAirports->firstWhere('home', true)['city'] ?? 'Keine Basis' }}</small>
    </article>
</section>

<section class="card game-panel" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">REAL WORLD MAP</span>
            <h3>Globales Streckennetz & Live-Flüge</h3>
        </div>
        <div class="split-actions">
            <span class="badge">OpenStreetMap</span>
            <span class="badge">{{ $mapAirports->where('network', true)->count() }} Netzwerk-Airports</span>
        </div>
    </div>

    <div class="filter-bar">
        <div class="field search">
            <label for="map-airport-search">Flughafen suchen</label>
            <input id="map-airport-search" type="search" list="map-airport-list" placeholder="IATA, ICAO, Stadt oder Flughafenname…">
            <datalist id="map-airport-list">
                @foreach($mapAirports as $airport)
                    <option value="{{ $airport['iata'] }} · {{ $airport['city'] }}">{{ $airport['name'] }}</option>
                @endforeach
            </datalist>
        </div>
        <div>
            <button class="button primary" type="button" id="map-airport-search-button"><x-icon name="map" :size="16" /> Auf Karte zeigen</button>
        </div>
        <div>
            <button class="button" type="button" id="map-network-fit"><x-icon name="route" :size="16" /> Netzwerk einpassen</button>
        </div>
    </div>

    <div id="airline-world-map" class="map-shell" aria-label="Interaktive Weltkarte"></div>

    <div class="map-legend" style="display:flex;flex-wrap:wrap;gap:14px;margin-top:14px;color:var(--muted);font-size:.82rem">
        <span>🟢 Heimatbasis</span>
        <span>🔵 Netzwerkflughafen</span>
        <span>⚪ weiterer Flughafen</span>
        <span>✈️ Live-Flug</span>
    </div>
</section>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card game-panel" data-table-filter>
        <div class="section-title">
            <div>
                <span class="eyebrow">ROUTE NETWORK</span>
                <h3>Routenübersicht</h3>
            </div>
            <span class="badge">{{ $mapRoutes->count() }} Strecken</span>
        </div>

        @if($mapRoutes->isEmpty())
            <div class="empty">Noch keine Strecke vorhanden. Lege unter Operations deine erste Route an.</div>
        @else
            <div class="filter-bar">
                <div class="field search">
                    <label>Route suchen</label>
                    <input type="search" data-table-search placeholder="FRA, MUC, Frankfurt, München…">
                </div>
                <div><span class="game-label">Treffer</span><div class="filter-count" data-table-count>{{ $mapRoutes->count() }}</div></div>
            </div>
            <div>
                @foreach($mapRoutes as $route)
                    <article class="map-route-card"
                             data-filter-row
                             data-search="{{ $route['origin']['iata'] }} {{ $route['destination']['iata'] }} {{ $route['origin']['city'] }} {{ $route['destination']['city'] }}">
                        <div>
                            <div class="route-strip">
                                <div class="route-airport">
                                    <strong>{{ $route['origin']['iata'] }}</strong>
                                    <span class="route-meta">{{ $route['origin']['city'] }}</span>
                                </div>
                                <div class="route-line"></div>
                                <div class="route-airport" style="text-align:right">
                                    <strong>{{ $route['destination']['iata'] }}</strong>
                                    <span class="route-meta">{{ $route['destination']['city'] }}</span>
                                </div>
                            </div>
                            <div class="route-meta" style="margin-top:9px">
                                {{ $route['origin']['city'] }} → {{ $route['destination']['city'] }} ·
                                {{ number_format($route['distance_km'], 0, ',', '.') }} km ·
                                {{ intdiv($route['block_minutes'], 60) }}h {{ $route['block_minutes'] % 60 }}m Blockzeit
                            </div>
                        </div>
                        <button class="icon-button" type="button" data-focus-route="{{ $route['id'] }}" title="Route auf Karte zeigen">
                            <x-icon name="map" :size="17" />
                        </button>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card game-panel">
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
                            <td>
                                <div class="finance-bar" style="min-width:90px"><span style="width:{{ $flight['progress_percent'] }}%"></span></div>
                                <span class="muted">{{ $flight['progress_percent'] }} %</span>
                            </td>
                            <td><span class="badge">{{ $flightStatusLabels[$flight['status']] ?? ucfirst($flight['status']) }}{{ $flight['delay_minutes'] > 0 ? ' · +'.$flight['delay_minutes'].' min' : '' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

<p class="footer-note">Kartengrundlage: OpenStreetMap. Flughäfen, Routen und Flugpositionen stammen aus deiner Airline-Empire-Spielwelt.</p>
@endsection

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const airports = @json($mapAirports->values());
    const routes = @json($mapRoutes);
    const flights = @json($mapFlights);

    if (!window.L || !document.getElementById('airline-world-map')) return;

    const map = L.map('airline-world-map', {
        zoomControl: true,
        worldCopyJump: true,
        minZoom: 2
    }).setView([48.5, 10.5], 4);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const airportMarkers = new Map();
    const routeLayers = new Map();
    const networkBounds = [];

    const airportIcon = (airport) => L.divIcon({
        className: '',
        html: '<div class="airport-marker '+(airport.home ? 'home' : (airport.network ? 'network' : ''))+'">'+airport.iata+'</div>',
        iconSize: airport.home ? [38,38] : (airport.network ? [34,34] : [28,28]),
        iconAnchor: airport.home ? [19,19] : (airport.network ? [17,17] : [14,14])
    });

    airports.forEach((airport) => {
        const marker = L.marker([airport.latitude, airport.longitude], {
            icon: airportIcon(airport),
            zIndexOffset: airport.home ? 600 : (airport.network ? 400 : 0),
            opacity: airport.network ? 1 : .72
        }).addTo(map);

        marker.bindPopup(
            '<div class="map-popup-title">'+airport.iata+' / '+airport.icao+'</div>'+
            '<div>'+airport.name+'</div>'+
            '<div class="map-popup-meta">'+airport.city+' · '+airport.country_code+'</div>'
        );
        airportMarkers.set(airport.id, marker);
        if (airport.network) networkBounds.push([airport.latitude, airport.longitude]);
    });

    const curvePoints = (a, b) => {
        const points = [];
        const latMid = (a.latitude + b.latitude) / 2;
        const lonMid = (a.longitude + b.longitude) / 2;
        const dx = b.longitude - a.longitude;
        const dy = b.latitude - a.latitude;
        const bend = Math.min(10, Math.max(1.4, Math.sqrt(dx*dx + dy*dy) * .10));
        const ctrl = [latMid + (dx >= 0 ? bend : -bend), lonMid - (dy >= 0 ? bend : -bend)];

        for (let i=0;i<=24;i++) {
            const t=i/24;
            const omt=1-t;
            points.push([
                omt*omt*a.latitude + 2*omt*t*ctrl[0] + t*t*b.latitude,
                omt*omt*a.longitude + 2*omt*t*ctrl[1] + t*t*b.longitude
            ]);
        }
        return points;
    };

    routes.forEach((route) => {
        const points = curvePoints(route.origin, route.destination);
        const shadow = L.polyline(points, {color:'#93c5fd',weight:7,opacity:.20,lineCap:'round'}).addTo(map);
        const line = L.polyline(points, {color:'#2563eb',weight:2.8,opacity:.86,lineCap:'round'}).addTo(map);
        line.bindTooltip(
            '<strong>'+route.origin.iata+' → '+route.destination.iata+'</strong><br>'+
            Math.round(route.distance_km).toLocaleString('de-DE')+' km',
            {sticky:true}
        );
        routeLayers.set(route.id, {line, shadow, points});
    });

    flights.forEach((flight) => {
        const icon = L.divIcon({
            className:'',
            html:'<div class="flight-marker">✈</div>',
            iconSize:[38,38],
            iconAnchor:[19,19]
        });
        const marker = L.marker([flight.latitude, flight.longitude], {icon,zIndexOffset:900}).addTo(map);
        marker.bindPopup(
            '<div class="map-popup-title">'+flight.flight_number+'</div>'+
            '<div>'+flight.origin+' → '+flight.destination+'</div>'+
            '<div class="map-popup-meta">'+(flight.aircraft || 'ohne Kennzeichen')+' · '+flight.progress_percent+' %</div>'
        );
    });

    const fitNetwork = () => {
        if (networkBounds.length >= 2) map.fitBounds(networkBounds, {padding:[45,45],maxZoom:7});
        else if (networkBounds.length === 1) map.setView(networkBounds[0], 7);
        else map.setView([48.5,10.5],4);
    };
    fitNetwork();

    const searchInput = document.getElementById('map-airport-search');
    const showAirport = () => {
        const q = (searchInput?.value || '').toLowerCase().trim();
        if (!q) return;
        const airport = airports.find((item) =>
            [item.iata,item.icao,item.city,item.name,item.country_code].join(' ').toLowerCase().includes(q.replace(/\s*·.*$/, ''))
        );
        if (!airport) return;
        map.setView([airport.latitude, airport.longitude], 9);
        airportMarkers.get(airport.id)?.openPopup();
    };

    document.getElementById('map-airport-search-button')?.addEventListener('click', showAirport);
    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); showAirport(); }
    });
    document.getElementById('map-network-fit')?.addEventListener('click', fitNetwork);

    document.querySelectorAll('[data-focus-route]').forEach((button) => {
        button.addEventListener('click', () => {
            const layer = routeLayers.get(button.dataset.focusRoute);
            if (!layer) return;
            map.fitBounds(L.latLngBounds(layer.points), {padding:[60,60],maxZoom:7});
            layer.line.setStyle({weight:5,color:'#0ea5e9'});
            setTimeout(() => layer.line.setStyle({weight:2.8,color:'#2563eb'}), 1400);
        });
    });
});
</script>
@endpush
