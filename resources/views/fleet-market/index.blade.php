@extends('layouts.app')

@section('title', 'Flottenmarkt · Airline Empire')
@section('eyebrow', 'FLEET PROCUREMENT')
@section('heading', 'Flottenmarkt')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $typeLabels = [
        'purchase_new' => 'Neukauf',
        'purchase_used' => 'Gebrauchtkauf',
        'lease' => 'Leasing',
    ];
    $statusLabels = [
        'ordered' => 'Bestellt',
        'delivered' => 'Ausgeliefert',
        'lease_ended' => 'Leasing beendet',
        'cancelled' => 'Storniert',
    ];
@endphp

<section class="grid grid-4">
    <article class="card metric">
        <div class="metric-icon"><x-icon name="cash" :size="19" /></div>
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong>{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Verfügbares Bankguthaben</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="fleet" :size="19" /></div>
        <span class="eyebrow">FLUGZEUGTYPEN</span>
        <strong>{{ $types->count() }}</strong>
        <small>Reale Muster im Katalog</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="plane" :size="19" /></div>
        <span class="eyebrow">NEU VERFÜGBAR</span>
        <strong>{{ $newTypes->count() }}</strong>
        <small>Aktive Produktionsmuster</small>
    </article>
    <article class="card metric">
        <div class="metric-icon"><x-icon name="schedule" :size="19" /></div>
        <span class="eyebrow">PIPELINE</span>
        <strong>{{ $procurements->where('status', 'ordered')->count() }}</strong>
        <small>Noch nicht ausgeliefert</small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">AIRCRAFT CATALOGUE</span>
            <h3>Flugzeugkatalog</h3>
        </div>
        <div class="split-actions">
            <span class="badge">{{ $types->count() }} reale Muster</span>
            <a class="button ghost" href="{{ route('fleet.index') }}"><x-icon name="fleet" :size="16" /> Meine Flotte</a>
        </div>
    </div>

    <div class="filter-bar" data-aircraft-filter>
        <div class="field search">
            <label>Suche</label>
            <input type="search" data-filter-search placeholder="z. B. Airbus, A350, Boeing, 787…">
        </div>
        <div class="field">
            <label>Hersteller</label>
            <select data-filter-manufacturer>
                <option value="">Alle Hersteller</option>
                @foreach($types->pluck('manufacturer')->unique()->sort()->values() as $manufacturer)
                    <option value="{{ $manufacturer }}">{{ $manufacturer }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label>Min. Sitze</label>
            <input type="number" min="0" step="10" data-filter-seats placeholder="z. B. 150">
        </div>
        <div class="field">
            <label>Min. Reichweite km</label>
            <input type="number" min="0" step="500" data-filter-range placeholder="z. B. 6000">
        </div>
        <div class="field">
            <label>Status</label>
            <select data-filter-status>
                <option value="">Alle</option>
                <option value="active">In Produktion</option>
                <option value="out_of_production">Bestandsmuster</option>
            </select>
        </div>
        <div>
            <span class="game-label">Treffer</span>
            <div class="filter-count" data-filter-count>{{ $types->count() }}</div>
        </div>

        <div class="aircraft-catalog" style="grid-column:1/-1;width:100%">

        @foreach($types as $type)
            @php
                $visualGroup = data_get($type->technical_data, 'visual_group', 'narrowbody');
                $fuelBurn = (int) data_get($type->technical_data, 'fuel_burn_l_per_hour', 0);
            @endphp
            <article class="aircraft-card"
                     data-aircraft-card
                     data-search="{{ $type->manufacturer }} {{ $type->model }} {{ $type->variant }} {{ $type->icao_type_code }}"
                     data-manufacturer="{{ $type->manufacturer }}"
                     data-seats="{{ (int) $type->typical_seats }}"
                     data-range="{{ (int) $type->range_km }}"
                     data-status="{{ $type->production_status }}">
                <x-aircraft-visual
                    :group="$visualGroup"
                    :label="$type->icao_type_code ?? $type->model"
                    :type-id="$type->id"
                    :manufacturer="$type->manufacturer"
                    :model="$type->model"
                />
                <div class="aircraft-card-body">
                    <h4>{{ $type->manufacturer }} {{ $type->model }}</h4>
                    <div class="aircraft-card-sub">{{ $type->variant }} · {{ $type->production_status === 'active' ? 'in Produktion' : 'Bestandsmuster' }}</div>
                    <div class="aircraft-specs">
                        <div class="aircraft-spec"><strong>{{ number_format((int) $type->typical_seats, 0, ',', '.') }}</strong><span>Sitze typisch</span></div>
                        <div class="aircraft-spec"><strong>{{ number_format((int) $type->range_km, 0, ',', '.') }} km</strong><span>Reichweite</span></div>
                        <div class="aircraft-spec"><strong>{{ number_format((int) $type->cruise_speed_kmh, 0, ',', '.') }} km/h</strong><span>Reiseflug</span></div>
                        <div class="aircraft-spec"><strong>{{ $fuelBurn > 0 ? number_format($fuelBurn, 0, ',', '.').' l/h' : '–' }}</strong><span>Game-Fuelburn</span></div>
                    </div>
                </div>
            </article>
        @endforeach
        </div>
    </div>
</section>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div><span class="eyebrow">MANUFACTURER</span><h3>Neuflugzeug bestellen</h3></div>
            <span class="badge">Aktive Produktion</span>
        </div>
        <form method="post" action="{{ route('fleet-market.new') }}" class="form-grid">
            @csrf
            <div class="field full">
                <label for="new_aircraft_type_id">Flugzeugmuster</label>
                <select id="new_aircraft_type_id" name="aircraft_type_id" data-searchable data-search-placeholder="Hersteller oder Flugzeugtyp suchen…" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($newTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->manufacturer }} {{ $type->model }} · {{ $type->typical_seats }} Sitze · {{ number_format($type->range_km, 0, ',', '.') }} km · {{ number_format($type->reference_purchase_price_minor / 100, 0, ',', '.') }} {{ $type->reference_currency }}</option>
                    @endforeach
                </select>
                <span class="help">Kaufpreis wird bei Bestellung gebucht; Nutzung erst nach Auslieferung.</span>
            </div>
            <div class="field full">
                <label>Kennzeichen <span class="muted">(optional)</span></label>
                <input name="registration" maxlength="16" placeholder="Automatisch, wenn leer">
            </div>
            <div class="field full">
                <button class="button primary" type="submit"><x-icon name="fleet" :size="17" /> Neuflugzeug bestellen</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="section-title">
            <div><span class="eyebrow">OPERATING LEASE</span><h3>Flugzeug leasen</h3></div>
            <span class="badge">36–84 Monate</span>
        </div>
        <form method="post" action="{{ route('fleet-market.lease') }}" class="form-grid">
            @csrf
            <div class="field full">
                <label for="lease_aircraft_type_id">Flugzeugmuster</label>
                <select id="lease_aircraft_type_id" name="aircraft_type_id" data-searchable data-search-placeholder="Leasingmuster suchen…" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($newTypes as $type)
                        @php($monthly = (int) ceil($type->reference_purchase_price_minor * 0.0085))
                        <option value="{{ $type->id }}">{{ $type->manufacturer }} {{ $type->model }} · {{ $type->typical_seats }} Sitze · {{ number_format($type->range_km, 0, ',', '.') }} km · ca. {{ number_format($monthly / 100, 0, ',', '.') }} {{ $type->reference_currency }}/Monat</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Laufzeit</label>
                <select name="lease_term_months" required>
                    <option value="36">36 Monate</option>
                    <option value="60" selected>60 Monate</option>
                    <option value="84">84 Monate</option>
                </select>
            </div>
            <div class="field">
                <label>Kennzeichen <span class="muted">(optional)</span></label>
                <input name="registration" maxlength="16" placeholder="Automatisch">
            </div>
            <div class="field full">
                <span class="help">Zwei Monatsraten Bereitstellungsgebühr; danach automatische Monatsraten über die Simulation.</span>
            </div>
            <div class="field full">
                <button class="button primary" type="submit"><x-icon name="finance" :size="17" /> Leasingvertrag abschließen</button>
            </div>
        </form>
    </section>
</div>

<section class="card" style="margin-top:18px" data-table-filter>
    <div class="section-title">
        <div><span class="eyebrow">SECOND HAND</span><h3>Gebrauchtflugzeugmarkt</h3></div>
        <span class="badge">{{ $usedOffers->count() }} Angebote</span>
    </div>
    @if($usedOffers->isEmpty())
        <div class="empty">Aktuell stehen keine Gebrauchtflugzeuge zum Verkauf.</div>
    @else
        <div class="filter-bar">
            <div class="field search">
                <label>Gebrauchtmarkt durchsuchen</label>
                <input type="search" data-table-search placeholder="Muster, Hersteller, Standort, Seriennummer…">
            </div>
            <div><span class="game-label">Treffer</span><div class="filter-count" data-table-count>{{ $usedOffers->count() }}</div></div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Muster</th><th>Baujahr</th><th>Stunden</th><th>Zyklen</th><th>Zustand</th><th>Standort</th><th>Preis</th><th>Aktion</th></tr></thead>
                <tbody>
                @foreach($usedOffers as $offer)
                    <tr data-filter-row data-search="{{ $offer->type->manufacturer }} {{ $offer->type->model }} {{ $offer->serial_number }} {{ $offer->locationAirport?->iata_code }}">
                        <td><strong>{{ $offer->type->manufacturer }} {{ $offer->type->model }}</strong><br><span class="muted">S/N {{ $offer->serial_number }}</span></td>
                        <td>{{ $offer->manufactured_on?->format('Y') ?? '–' }}</td>
                        <td>{{ number_format((float) $offer->flight_hours, 0, ',', '.') }} h</td>
                        <td>{{ number_format((int) $offer->flight_cycles, 0, ',', '.') }}</td>
                        <td>{{ number_format((float) $offer->condition_percent, 1, ',', '.') }} %</td>
                        <td>{{ $offer->locationAirport?->iata_code ?? '–' }}</td>
                        <td><strong>{{ number_format($offer->price_minor / 100, 0, ',', '.') }} {{ $offer->currency }}</strong></td>
                        <td>
                            <form method="post" action="{{ route('fleet-market.used', $offer) }}">
                                @csrf
                                <input name="registration" maxlength="16" placeholder="Kennzeichen optional" style="min-width:145px;margin-bottom:6px">
                                <button class="button primary" type="submit">Kaufen</button>
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
        <div><span class="eyebrow">DELIVERY PIPELINE</span><h3>Bestellungen & Verträge</h3></div>
        <span class="badge">{{ $procurements->count() }} Vorgänge</span>
    </div>
    @if($procurements->isEmpty())
        <div class="empty">Noch keine Beschaffungsvorgänge vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Kennzeichen</th><th>Art</th><th>Muster</th><th>Bestellt</th><th>Auslieferung</th><th>Monatsrate</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($procurements as $item)
                    <tr>
                        <td><strong>{{ $item->registration }}</strong></td>
                        <td>{{ $typeLabels[$item->procurement_type] ?? $item->procurement_type }}</td>
                        <td>{{ $item->type->manufacturer }} {{ $item->type->model }}</td>
                        <td>{{ $item->ordered_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>{{ $item->delivered_at ? $item->delivered_at->timezone('Europe/Berlin')->format('d.m.Y H:i') : $item->delivery_due_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>{{ $item->monthly_payment_minor > 0 ? number_format($item->monthly_payment_minor / 100, 2, ',', '.').' '.$item->currency : '–' }}</td>
                        <td><span class="badge">{{ $statusLabels[$item->status] ?? $item->status }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Technische Werte, Kaufpreise und Verbrauchsdaten sind simulationsbezogene Referenzwerte und keine Herstellerangebote oder verbindlichen Leistungsdaten.</p>
@endsection
