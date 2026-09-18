@extends('layouts.app')

@section('title', 'Meine Flotte · Airline Empire')
@section('eyebrow', 'FLEET MANAGEMENT')
@section('heading', 'Meine Flotte')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $statusLabels = [
        'available' => 'Verfügbar',
        'in_flight' => 'Im Flug',
        'maintenance' => 'Wartung',
        'grounded' => 'Gesperrt',
    ];
    $ownershipLabels = [
        'owned' => 'Eigentum',
        'leased' => 'Leasing',
    ];
@endphp

<section class="grid grid-4">
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="fleet" :size="19" /></div>
        <span class="eyebrow">GESAMTFLOTTE</span>
        <strong>{{ $fleetRows->count() }}</strong>
        <small>{{ $ownedCount }} Eigentum · {{ $leasedCount }} Leasing</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="status" :size="19" /></div>
        <span class="eyebrow">VERFÜGBAR</span>
        <strong>{{ $availableCount }}</strong>
        <small>aktuell einsatzbereite Flugzeuge</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="finance" :size="19" /></div>
        <span class="eyebrow">GESCHÄTZTER WERT</span>
        <strong>{{ number_format($estimatedFleetValueMinor / 100, 0, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>verkaufbarer Eigentumsbestand</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="market" :size="19" /></div>
        <span class="eyebrow">BESCHAFFUNG</span>
        <strong>Markt</strong>
        <small><a href="{{ route('fleet-market.index') }}">Flottenmarkt öffnen →</a></small>
    </article>
</section>

<section class="card game-panel" style="margin-top:18px" data-aircraft-filter>
    <div class="section-title">
        <div>
            <span class="eyebrow">OWNED & LEASED AIRCRAFT</span>
            <h3>Flottenbestand</h3>
        </div>
        <div class="split-actions">
            <span class="badge">{{ $fleetRows->count() }} Flugzeuge</span>
            <a class="button primary" href="{{ route('fleet-market.index') }}"><x-icon name="market" :size="16" /> Flugzeug beschaffen</a>
        </div>
    </div>

    @if($fleetRows->isEmpty())
        <div class="empty">Deine Airline besitzt noch kein ausgeliefertes Flugzeug.</div>
    @else
        <div class="filter-bar">
            <div class="field search">
                <label>Suche</label>
                <input type="search" data-filter-search placeholder="Kennzeichen, Hersteller, Modell…">
            </div>
            <div class="field">
                <label>Hersteller</label>
                <select data-filter-manufacturer>
                    <option value="">Alle Hersteller</option>
                    @foreach($fleetRows->pluck('aircraft.type.manufacturer')->filter()->unique()->sort()->values() as $manufacturer)
                        <option value="{{ $manufacturer }}">{{ $manufacturer }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Min. Sitze</label>
                <input type="number" min="0" step="10" data-filter-seats placeholder="z. B. 150">
            </div>
            <div class="field">
                <label>Min. Reichweite</label>
                <input type="number" min="0" step="500" data-filter-range placeholder="km">
            </div>
            <div><span class="game-label">Treffer</span><div class="filter-count" data-filter-count>{{ $fleetRows->count() }}</div></div>
        </div>

        <div class="aircraft-catalog">
            @foreach($fleetRows as $row)
                @php
                    $aircraft = $row['aircraft'];
                    $procurement = $row['procurement'];
                    $visualGroup = data_get($aircraft->type?->technical_data, 'visual_group', 'narrowbody');
                    $seats = (int) data_get($aircraft->configuration, 'seats', $aircraft->type?->typical_seats ?? 0);
                @endphp
                <article class="aircraft-card"
                         data-aircraft-card
                         data-search="{{ $aircraft->registration }} {{ $aircraft->type?->manufacturer }} {{ $aircraft->type?->model }} {{ $aircraft->type?->variant }}"
                         data-manufacturer="{{ $aircraft->type?->manufacturer }}"
                         data-seats="{{ $seats }}"
                         data-range="{{ (int) ($aircraft->type?->range_km ?? 0) }}"
                         data-status="{{ $aircraft->status }}">
                    <x-aircraft-visual :group="$visualGroup" :label="$aircraft->registration" />

                    <div class="aircraft-card-body">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:start">
                            <div>
                                <h4>{{ $aircraft->type?->manufacturer }} {{ $aircraft->type?->model }}</h4>
                                <div class="aircraft-card-sub">{{ $aircraft->registration }} · {{ $aircraft->type?->variant }}</div>
                            </div>
                            <span class="badge">{{ $ownershipLabels[$aircraft->ownership_type] ?? ucfirst($aircraft->ownership_type) }}</span>
                        </div>

                        <div class="aircraft-specs">
                            <div class="aircraft-spec"><strong>{{ $seats }}</strong><span>Sitze</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format((int) ($aircraft->type?->range_km ?? 0), 0, ',', '.') }} km</strong><span>Reichweite</span></div>
                            <div class="aircraft-spec"><strong>{{ $aircraft->currentAirport?->iata_code ?? '–' }}</strong><span>Standort</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format((float) $aircraft->condition_percent, 1, ',', '.') }} %</strong><span>Zustand</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format((float) $aircraft->flight_hours, 0, ',', '.') }} h</strong><span>Flugstunden</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format((int) $aircraft->flight_cycles, 0, ',', '.') }}</strong><span>Zyklen</span></div>
                        </div>

                        <div class="list" style="margin-top:12px">
                            <div class="row"><span class="muted">Status</span><span class="badge">{{ $statusLabels[$aircraft->status] ?? ucfirst($aircraft->status) }}</span></div>
                            <div class="row"><span class="muted">Baujahr</span><strong>{{ $aircraft->manufactured_on?->format('Y') ?? '–' }}</strong></div>
                            @if($aircraft->ownership_type === 'leased')
                                <div class="row"><span class="muted">Leasingrate</span><strong>{{ $procurement ? number_format($procurement->monthly_payment_minor / 100, 0, ',', '.').' '.$procurement->currency.'/Monat' : '–' }}</strong></div>
                            @else
                                <div class="row"><span class="muted">Geschätzter Verkaufswert</span><strong class="kpi-positive">{{ number_format($row['estimated_sale_minor'] / 100, 0, ',', '.') }} {{ $aircraft->currency }}</strong></div>
                            @endif
                        </div>

                        @if($aircraft->ownership_type === 'owned')
                            @if($row['sale_block_reason'])
                                <div class="footer-note">{{ $row['sale_block_reason'] }}</div>
                                <button class="button" type="button" disabled style="width:100%;margin-top:10px">Verkauf aktuell nicht möglich</button>
                            @else
                                <form method="post" action="{{ route('fleet.sell', $aircraft) }}" style="margin-top:12px"
                                      onsubmit="return confirm('Soll {{ $aircraft->registration }} wirklich für ca. {{ number_format($row['estimated_sale_minor'] / 100, 0, ',', '.') }} {{ $aircraft->currency }} verkauft werden?');">
                                    @csrf
                                    <button class="button danger" type="submit" style="width:100%">Flugzeug verkaufen</button>
                                </form>
                            @endif
                        @else
                            <div class="footer-note">Leasingflugzeuge können nicht verkauft werden.</div>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>

<p class="footer-note">Der Verkaufswert ist ein Spielwert aus Anschaffungswert, Alter, Flugstunden und technischem Zustand. Ein Verkauf wird sofort im Ledger verbucht; das Flugzeug wandert anschließend in den Gebrauchtmarkt der Spielwelt.</p>
@endsection
