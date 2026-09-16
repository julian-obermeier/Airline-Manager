@extends('layouts.app')

@section('title', 'Dashboard · Airline Empire')
@section('eyebrow', 'COMMAND CENTER')
@section('heading', $airline->name)
@section('subheading', $airline->homeAirport->city.' · '.$airline->homeAirport->iata_code.' / '.$airline->homeAirport->icao_code.' · Welt '.$world->name)

@section('content')
<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong class="kpi-positive">{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Ledger-Konto CASH</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">FLOTTE</span>
        <strong>{{ $airline->aircraft_count }}</strong>
        <small>Konkrete Flugzeuge</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">ROUTEN</span>
        <strong>{{ $airline->routes_count }}</strong>
        <small>Aktuell angelegt</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">FLÜGE</span>
        <strong>{{ $airline->flights_count }}</strong>
        <small>Fluginstanzen</small>
    </article>
</section>

<section class="card hero" style="margin-top:18px">
    <div>
        <span class="eyebrow">AIRLINE PROFILE</span>
        <h2>{{ $airline->name }} ist betriebsbereit.</h2>
        <p class="muted">Deine Airline ist mit Heimatflughafen, Geschäftsmodell und Finanz-Ledger vollständig in der Welt angelegt. Als Nächstes werden Flottenbeschaffung, Routenplanung und konkrete Flugoperationen freigeschaltet.</p>
        <div class="world-meta">
            <span class="badge">{{ strtoupper($airline->business_model) }}</span>
            <span class="badge">{{ strtoupper($airline->service_concept ?? 'balanced') }}</span>
            <span class="badge">{{ strtoupper($airline->target_group ?? 'mixed') }}</span>
            @if($airline->iata_code)<span class="badge">IATA {{ $airline->iata_code }}</span>@endif
            @if($airline->icao_code)<span class="badge">ICAO {{ $airline->icao_code }}</span>@endif
            @if($airline->callsign)<span class="badge">{{ $airline->callsign }}</span>@endif
        </div>
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
                <span class="eyebrow">WORLD</span>
                <h3>Simulationsstatus</h3>
            </div>
            <span class="badge">{{ $world->status }}</span>
        </div>
        <div class="list">
            <div class="row"><span class="muted">Spielwelt</span><strong>{{ $world->name }}</strong></div>
            <div class="row"><span class="muted">Geschwindigkeit</span><strong>{{ number_format((float) $world->speed_multiplier, 2, ',', '.') }}×</strong></div>
            <div class="row"><span class="muted">Simulationszeit</span><strong>{{ $world->simulated_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</strong></div>
            <div class="row"><span class="muted">Basiswährung</span><strong>{{ data_get($world->settings, 'currency', 'EUR') }}</strong></div>
        </div>
    </section>
</div>

<p class="footer-note">Phase 1 Gameplay-Basis: Account, Welten, Airline-Gründung und Ledger sind jetzt aktiv. Noch nicht implementierte Module werden nicht als funktionsfähige Buttons dargestellt.</p>
@endsection
