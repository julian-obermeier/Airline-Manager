@extends('layouts.app')

@section('title', 'Airline gründen · Airline Empire')
@section('eyebrow', 'AIRLINE CREATION')
@section('heading', 'Deine Airline gründen')
@section('subheading', 'Welt: '.$world->name.' · Startkapital: '.number_format((int) data_get($world->settings, 'starting_capital_minor', 5000000000) / 100, 2, ',', '.').' '.data_get($world->settings, 'currency', 'EUR'))

@section('content')
<section class="card form-card">
    <form method="post" action="{{ route('airlines.store') }}">
        @csrf
        <div class="form-grid">
            <div class="field full">
                <label for="name">Airline-Name</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required autofocus>
                <span class="help">Der Name muss innerhalb der gewählten Spielwelt eindeutig als URL-Slug abbildbar sein.</span>
            </div>

            <div class="field full">
                <label for="home_airport_id">Heimatflughafen</label>
                <select id="home_airport_id" name="home_airport_id" data-searchable data-search-placeholder="Flughafen nach Name, Stadt, IATA oder Land suchen…" required>
                    <option value="">Bitte auswählen</option>
                    <x-airport-options :airports="$airports" :selected="old('home_airport_id')" />
                </select>
            </div>

            <div class="field">
                <label for="iata_code">IATA-Code</label>
                <input id="iata_code" name="iata_code" value="{{ old('iata_code') }}" maxlength="2" placeholder="z. B. AB">
                <span class="help">Optional, 2 alphanumerische Zeichen.</span>
            </div>

            <div class="field">
                <label for="icao_code">ICAO-Code</label>
                <input id="icao_code" name="icao_code" value="{{ old('icao_code') }}" maxlength="3" placeholder="z. B. ABE">
                <span class="help">Optional, 3 alphanumerische Zeichen.</span>
            </div>

            <div class="field full">
                <label for="callsign">Callsign</label>
                <input id="callsign" name="callsign" value="{{ old('callsign') }}" maxlength="40" placeholder="z. B. EMPIRE">
            </div>

            <div class="field">
                <label for="business_model">Geschäftsmodell</label>
                <select id="business_model" name="business_model" required>
                    <option value="full_service" @selected(old('business_model') === 'full_service')>Full Service Carrier</option>
                    <option value="low_cost" @selected(old('business_model') === 'low_cost')>Low Cost Carrier</option>
                    <option value="regional" @selected(old('business_model') === 'regional')>Regional Airline</option>
                    <option value="cargo" @selected(old('business_model') === 'cargo')>Cargo Airline</option>
                    <option value="hybrid" @selected(old('business_model', 'hybrid') === 'hybrid')>Hybrid</option>
                </select>
            </div>

            <div class="field">
                <label for="service_concept">Servicekonzept</label>
                <select id="service_concept" name="service_concept">
                    <option value="economy" @selected(old('service_concept') === 'economy')>Preisorientiert</option>
                    <option value="balanced" @selected(old('service_concept', 'balanced') === 'balanced')>Ausgewogen</option>
                    <option value="premium" @selected(old('service_concept') === 'premium')>Premium</option>
                </select>
            </div>

            <div class="field full">
                <label for="target_group">Zielgruppe</label>
                <select id="target_group" name="target_group">
                    <option value="leisure" @selected(old('target_group') === 'leisure')>Freizeitverkehr</option>
                    <option value="business" @selected(old('target_group') === 'business')>Geschäftsverkehr</option>
                    <option value="mixed" @selected(old('target_group', 'mixed') === 'mixed')>Gemischt</option>
                </select>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:22px">
            <button class="button primary" type="submit">Airline verbindlich gründen</button>
        </div>
    </form>
</section>
@endsection
