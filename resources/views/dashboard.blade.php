@extends('layouts.app')

@section('title', 'Dashboard · Airline Empire')
@section('eyebrow', 'COMMAND CENTER')
@section('heading', $airline->name)
@section('subheading', $airline->homeAirport->city.' · '.$airline->homeAirport->iata_code.' / '.$airline->homeAirport->icao_code.' · '.$world->name)

@section('content')
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
        <strong>{{ $airline->aircraft_count }}</strong>
        <small>Aktive Flugzeuge</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="route" :size="19" /></div>
        <span class="eyebrow">ROUTEN</span>
        <strong>{{ $airline->routes_count }}</strong>
        <small>Aktive Strecken</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="plane" :size="19" /></div>
        <span class="eyebrow">FLÜGE</span>
        <strong>{{ $airline->flights_count }}</strong>
        <small>Gesamte Fluginstanzen</small>
    </article>
</section>

<section class="card hero" style="margin-top:18px">
    <div>
        <span class="eyebrow">AIRLINE CONTROL CENTER</span>
        <h2>{{ $airline->name }} auf einen Blick.</h2>
        <p class="muted">Plane Flüge, steuere Flotte und Crew, optimiere Preise und beobachte deine Marktposition – alle Bereiche greifen direkt ineinander.</p>

        <div class="world-meta" style="margin:18px 0">
            <span class="badge">{{ strtoupper($airline->business_model) }}</span>
            <span class="badge">{{ strtoupper($airline->service_concept ?? 'balanced') }}</span>
            <span class="badge">{{ strtoupper($airline->target_group ?? 'mixed') }}</span>
            @if($airline->iata_code)<span class="badge">IATA {{ $airline->iata_code }}</span>@endif
            @if($airline->icao_code)<span class="badge">ICAO {{ $airline->icao_code }}</span>@endif
        </div>

        <div class="hero-actions">
            <a class="button primary" href="{{ route('operations.index') }}"><x-icon name="operations" :size="17" /> Operations öffnen</a>
            <a class="button" href="{{ route('schedules.index') }}"><x-icon name="schedule" :size="17" /> Flugpläne</a>
            <a class="button" href="{{ route('finance.index') }}"><x-icon name="finance" :size="17" /> Finanzen</a>
        </div>
    </div>

    <div class="hero-visual">
        <x-aircraft-visual group="widebody" label="Airline Empire" style="width:100%;min-height:175px" />
    </div>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">SCHNELLZUGRIFF</span>
            <h3>Wichtige Bereiche</h3>
        </div>
    </div>

    <div class="quick-grid">
        <a class="quick-link" href="{{ route('fleet-market.index') }}">
            <span class="quick-link-icon"><x-icon name="fleet" /></span>
            <div><strong>Flottenmarkt</strong><span>Flugzeuge kaufen, leasen und übernehmen</span></div>
        </a>
        <a class="quick-link" href="{{ route('revenue-management.index') }}">
            <span class="quick-link-icon"><x-icon name="revenue" /></span>
            <div><strong>Revenue Management</strong><span>Dynamische Preise und Fare-Buckets steuern</span></div>
        </a>
        <a class="quick-link" href="{{ route('market.index') }}">
            <span class="quick-link-icon"><x-icon name="market" /></span>
            <div><strong>Markt & Konkurrenz</strong><span>Marktanteile und Wettbewerber vergleichen</span></div>
        </a>
        <a class="quick-link" href="{{ route('crew.index') }}">
            <span class="quick-link-icon"><x-icon name="crew" /></span>
            <div><strong>Personal & Crew</strong><span>Besatzungen, Type Ratings und Payroll</span></div>
        </a>
        <a class="quick-link" href="{{ route('airport-operations.index') }}">
            <span class="quick-link-icon"><x-icon name="airport" /></span>
            <div><strong>Airports & Slots</strong><span>Stationen, Slots und Kapazitäten</span></div>
        </a>
        <a class="quick-link" href="{{ route('map.index') }}">
            <span class="quick-link-icon"><x-icon name="map" /></span>
            <div><strong>Weltkarte</strong><span>Netzwerk und aktive Flüge visualisieren</span></div>
        </a>
    </div>
</section>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">BASE</span>
                <h3>Heimatflughafen</h3>
            </div>
            <span class="badge">{{ $airline->homeAirport->country_code }}</span>
        </div>
        <div class="list">
            <div class="row"><span class="muted">Flughafen</span><strong>{{ $airline->homeAirport->name }}</strong></div>
            <div class="row"><span class="muted">Stadt</span><strong>{{ $airline->homeAirport->city }}</strong></div>
            <div class="row"><span class="muted">Codes</span><strong>{{ $airline->homeAirport->iata_code }} / {{ $airline->homeAirport->icao_code }}</strong></div>
            <div class="row"><span class="muted">Zeitzone</span><strong>{{ $airline->homeAirport->timezone }}</strong></div>
        </div>
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">SIMULATION</span>
                <h3>Spielwelt</h3>
            </div>
            <span class="badge">{{ $world->status === 'active' ? 'Aktiv' : ucfirst($world->status) }}</span>
        </div>
        <div class="list">
            <div class="row"><span class="muted">Spielwelt</span><strong>{{ $world->name }}</strong></div>
            <div class="row"><span class="muted">Geschwindigkeit</span><strong>{{ number_format((float) $world->speed_multiplier, 2, ',', '.') }}×</strong></div>
            <div class="row"><span class="muted">Simulationszeit</span><strong>{{ $world->simulated_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</strong></div>
            <div class="row"><span class="muted">Basiswährung</span><strong>{{ data_get($world->settings, 'currency', 'EUR') }}</strong></div>
        </div>
    </section>
</div>
@endsection
