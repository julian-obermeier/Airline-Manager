@extends('layouts.app')

@section('title', 'Streckenplaner · Airline Empire')
@section('eyebrow', 'NETWORK PLANNING')
@section('heading', 'Streckenplaner 2.0')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
<section class="card game-panel">
    <div class="section-title">
        <div>
            <span class="eyebrow">ROUTE ANALYSIS</span>
            <h3>Neue Strecke analysieren</h3>
        </div>
        <span class="badge"><x-icon name="route" :size="14" /> Wirtschaftlichkeit vor Eröffnung prüfen</span>
    </div>

    <form method="get" action="{{ route('route-planner.index') }}" class="form-grid">
        <div class="field">
            <label for="planner_origin">Startflughafen</label>
            <select id="planner_origin" name="origin" data-searchable data-search-placeholder="Startflughafen suchen…" required>
                <option value="">Bitte auswählen</option>
                <x-airport-options :airports="$airports" :selected="$origin?->id" />
            </select>
        </div>
        <div class="field">
            <label for="planner_destination">Zielflughafen</label>
            <select id="planner_destination" name="destination" data-searchable data-search-placeholder="Zielflughafen suchen…" required>
                <option value="">Bitte auswählen</option>
                <x-airport-options :airports="$airports" :selected="$destination?->id" />
            </select>
        </div>
        <div class="field full">
            <button class="button primary" type="submit"><x-icon name="revenue" :size="17" /> Strecke analysieren</button>
        </div>
    </form>
</section>

@if($analysis)
<section class="grid grid-4" style="margin-top:18px">
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="route" :size="19" /></div>
        <span class="eyebrow">DISTANZ</span>
        <strong>{{ number_format($analysis['distance_km'], 0, ',', '.') }} km</strong>
        <small>{{ intdiv($analysis['block_minutes'], 60) }}h {{ $analysis['block_minutes'] % 60 }}m geplante Blockzeit</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="market" :size="19" /></div>
        <span class="eyebrow">NACHFRAGE</span>
        <strong>{{ $analysis['demand_label'] }}</strong>
        <small>Index {{ number_format($analysis['demand_index'], 2, ',', '.') }}</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="market" :size="19" /></div>
        <span class="eyebrow">KONKURRENZ</span>
        <strong>{{ $analysis['competitor_count'] }}</strong>
        <small>{{ $analysis['competitor_count'] === 1 ? 'direkte Airline' : 'direkte Airlines' }}</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="status" :size="19" /></div>
        <span class="eyebrow">CHANCENWERT</span>
        <strong>{{ number_format($analysis['opportunity_score'], 0, ',', '.') }}/100</strong>
        <small>{{ $analysis['reachable_aircraft_count'] }} passende eigene Flugzeuge</small>
    </article>
</section>

<section class="card route-analysis-hero game-panel" style="margin-top:18px">
    <div>
        <span class="eyebrow">PROPOSED MARKET</span>
        <div class="route-strip route-strip-large" style="margin-top:10px">
            <div class="route-airport">
                <strong>{{ $analysis['origin']->iata_code }}</strong>
                <span class="route-meta">{{ $analysis['origin']->city }}</span>
            </div>
            <div class="route-line"></div>
            <div class="route-airport" style="text-align:right">
                <strong>{{ $analysis['destination']->iata_code }}</strong>
                <span class="route-meta">{{ $analysis['destination']->city }}</span>
            </div>
        </div>

        <div class="route-analysis-facts">
            <div><span>Economy-Basis</span><strong>{{ number_format($analysis['default_fares']['economy_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
            <div><span>Business-Basis</span><strong>{{ number_format($analysis['default_fares']['business_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
            <div><span>Markt Ø Economy</span><strong>{{ number_format($analysis['market_average_fare_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
            <div><span>Neue Stationskosten</span><strong>{{ number_format($analysis['new_station_cost_minor'] / 100, 0, ',', '.') }} {{ $airline->base_currency }}/Monat</strong></div>
        </div>

        <div class="hero-actions">
            @if($analysis['existing_route'])
                <span class="badge">Route bereits aktiv</span>
                <a class="button primary" href="{{ route('operations.index') }}"><x-icon name="operations" :size="16" /> Flug planen</a>
                <a class="button" href="{{ route('revenue-management.index') }}"><x-icon name="revenue" :size="16" /> Preise steuern</a>
            @else
                <form method="post" action="{{ route('operations.routes.store') }}">
                    @csrf
                    <input type="hidden" name="origin_airport_id" value="{{ $analysis['origin']->id }}">
                    <input type="hidden" name="destination_airport_id" value="{{ $analysis['destination']->id }}">
                    <button class="button primary" type="submit"><x-icon name="route" :size="16" /> Route eröffnen</button>
                </form>
            @endif

            <a class="button" href="{{ route('route-planner.index', ['origin' => $analysis['destination']->id, 'destination' => $analysis['origin']->id]) }}">
                Gegenrichtung analysieren
            </a>
            <a class="button" href="{{ route('map.index') }}"><x-icon name="map" :size="16" /> Weltkarte</a>
        </div>
    </div>

    <div class="planner-score-panel">
        <span class="game-label">Route Opportunity</span>
        <div class="planner-score">{{ number_format($analysis['opportunity_score'], 0, ',', '.') }}</div>
        <div class="muted">Spielinterner Chancenwert aus Nachfrage, Wettbewerb und eigener Flottenreichweite.</div>
    </div>
</section>

<section class="card game-panel" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">FLEET FIT & UNIT ECONOMICS</span>
            <h3>Passende eigene Flugzeuge</h3>
        </div>
        <div class="split-actions">
            <span class="badge">{{ $analysis['reachable_aircraft_count'] }} reichweitenfähig</span>
            <span class="badge">{{ $analysis['aircraft_at_origin_count'] }} aktuell am Start</span>
        </div>
    </div>

    @if($analysis['candidate_aircraft']->isEmpty())
        <div class="empty">
            Kein eigenes Flugzeug hat genügend Reichweite für diese Strecke.
            <a href="{{ route('fleet-market.index') }}">Im Flottenmarkt nach passenden Mustern suchen →</a>
        </div>
    @else
        <div class="planner-aircraft-grid">
            @foreach($analysis['candidate_aircraft'] as $candidate)
                @php
                    $aircraft = $candidate['aircraft'];
                    $visualGroup = data_get($aircraft->type?->technical_data, 'visual_group', 'narrowbody');
                @endphp
                <article class="aircraft-card planner-aircraft-card">
                    <x-aircraft-visual
                        :group="$visualGroup"
                        :label="$aircraft->registration"
                        :type-id="$aircraft->type?->id"
                        :manufacturer="$aircraft->type?->manufacturer"
                        :model="$aircraft->type?->model"
                    />
                    <div class="aircraft-card-body">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
                            <div>
                                <h4>{{ $aircraft->type?->manufacturer }} {{ $aircraft->type?->model }}</h4>
                                <div class="aircraft-card-sub">{{ $aircraft->registration }} · {{ $candidate['seats'] }} Sitze</div>
                            </div>
                            <span class="badge">{{ $candidate['at_origin'] ? 'Am Start' : ($aircraft->currentAirport?->iata_code ?? 'Unterwegs') }}</span>
                        </div>

                        <div class="planner-profit {{ $candidate['fully_allocated_profit_minor'] >= 0 ? 'positive' : 'negative' }}">
                            <span>Vollkosten-Ergebnis / Flug</span>
                            <strong>{{ $candidate['fully_allocated_profit_minor'] >= 0 ? '+' : '' }}{{ number_format($candidate['fully_allocated_profit_minor'] / 100, 0, ',', '.') }} {{ $airline->base_currency }}</strong>
                        </div>

                        <div class="aircraft-specs">
                            <div class="aircraft-spec"><strong>{{ number_format($candidate['expected_load_factor'] * 100, 0, ',', '.') }} %</strong><span>Erwartete Auslastung</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format($candidate['break_even_load_factor'] * 100, 0, ',', '.') }} %</strong><span>Break-even LF</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format($candidate['expected_passengers'], 0, ',', '.') }}</strong><span>Passagiere</span></div>
                            <div class="aircraft-spec"><strong>{{ number_format($candidate['range_margin_km'], 0, ',', '.') }} km</strong><span>Reichweitenreserve</span></div>
                        </div>

                        <div class="list" style="margin-top:12px">
                            <div class="row"><span class="muted">Umsatz</span><strong class="kpi-positive">{{ number_format($candidate['revenue_minor'] / 100, 0, ',', '.') }}</strong></div>
                            <div class="row"><span class="muted">Treibstoff</span><strong>{{ number_format($candidate['fuel_cost_minor'] / 100, 0, ',', '.') }}</strong></div>
                            <div class="row"><span class="muted">Flugbetrieb</span><strong>{{ number_format($candidate['operating_cost_minor'] / 100, 0, ',', '.') }}</strong></div>
                            <div class="row"><span class="muted">Airport & Slots</span><strong>{{ number_format($candidate['airport_fees_minor'] / 100, 0, ',', '.') }}</strong></div>
                            <div class="row"><span class="muted">Crew anteilig</span><strong>{{ number_format($candidate['allocated_crew_cost_minor'] / 100, 0, ',', '.') }}</strong></div>
                            <div class="row"><span class="muted">Stationen anteilig</span><strong>{{ number_format($candidate['allocated_station_cost_minor'] / 100, 0, ',', '.') }}</strong></div>
                            <div class="row"><span class="muted">Flugbeitrag</span><strong class="{{ $candidate['flight_contribution_minor'] >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ $candidate['flight_contribution_minor'] >= 0 ? '+' : '' }}{{ number_format($candidate['flight_contribution_minor'] / 100, 0, ',', '.') }}</strong></div>
                        </div>

                        <div class="footer-note">Hochrechnung 1 Flug/Tag: {{ $candidate['monthly_profit_7x_minor'] >= 0 ? '+' : '' }}{{ number_format($candidate['monthly_profit_7x_minor'] / 100, 0, ',', '.') }} {{ $airline->base_currency }}/30 Tage</div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>

<section class="grid grid-2" style="margin-top:18px">
    <article class="card game-panel">
        <div class="section-title">
            <div>
                <span class="eyebrow">MARKET COMPETITION</span>
                <h3>Direkte Wettbewerber</h3>
            </div>
            <span class="badge">{{ $analysis['competitor_count'] }}</span>
        </div>

        @if($analysis['competitors']->isEmpty())
            <div class="empty">Aktuell bedient keine andere Airline diese Richtung in der Spielwelt.</div>
        @else
            <div class="list">
                @foreach($analysis['competitors'] as $competitor)
                    <div class="row">
                        <div>
                            <strong>{{ $competitor['airline_name'] }}</strong>
                            <div class="route-meta">{{ $competitor['airline_iata'] ?? '–' }} · {{ $competitor['weekly_frequency'] }} Flüge / 7 Tage</div>
                        </div>
                        <strong>{{ number_format($competitor['economy_fare_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
                    </div>
                @endforeach
            </div>
        @endif
    </article>

    <article class="card game-panel">
        <div class="section-title">
            <div>
                <span class="eyebrow">FORECAST NOTES</span>
                <h3>So wird gerechnet</h3>
            </div>
        </div>
        <div class="list">
            <div class="row"><span>Nachfrage</span><strong>Route-Demand-Index + Wettbewerb</strong></div>
            <div class="row"><span>Umsatz</span><strong>Cabin-Mix × Basistarife × erwartete Belegung</strong></div>
            <div class="row"><span>Treibstoff</span><strong>Blockzeit × Typverbrauch + Reserve</strong></div>
            <div class="row"><span>Airportkosten</span><strong>Bewegung + Sitz + Passagier + Slots</strong></div>
            <div class="row"><span>Vollkosten</span><strong>Flugkosten + anteilige Crew + Stationen</strong></div>
        </div>
        <p class="footer-note">Die Prognose ist ein Spielmodell. Das tatsächliche Ergebnis hängt später von Buchungen, dynamischem Pricing, Marketing, Konkurrenz, Delay und realer Crew-/Slot-Verfügbarkeit ab.</p>
    </article>
</section>
@endif

<section class="card game-panel" style="margin-top:18px" data-table-filter>
    <div class="section-title">
        <div>
            <span class="eyebrow">ROUTE OPPORTUNITIES</span>
            <h3>Zielideen ab {{ $origin?->iata_code ?? 'Start' }}</h3>
        </div>
        <span class="badge">{{ $opportunities->count() }} Vorschläge</span>
    </div>

    <div class="filter-bar">
        <div class="field search">
            <label>Ziele durchsuchen</label>
            <input type="search" data-table-search placeholder="IATA, Stadt, Land oder Flughafen…">
        </div>
        <div><span class="game-label">Treffer</span><div class="filter-count" data-table-count>{{ $opportunities->count() }}</div></div>
    </div>

    <div class="opportunity-grid">
        @foreach($opportunities as $opportunity)
            @php($airport = $opportunity['airport'])
            <a class="opportunity-card"
               data-filter-row
               data-search="{{ $airport->iata_code }} {{ $airport->icao_code }} {{ $airport->city }} {{ $airport->name }} {{ $airport->country_code }}"
               href="{{ route('route-planner.index', ['origin' => $origin?->id, 'destination' => $airport->id]) }}">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:start">
                    <div>
                        <strong class="opportunity-iata">{{ $airport->iata_code }}</strong>
                        <div>{{ $airport->city }}</div>
                        <div class="route-meta">{{ $airport->country_code }} · {{ $airport->name }}</div>
                    </div>
                    <div class="opportunity-score">{{ number_format($opportunity['score'], 0, ',', '.') }}</div>
                </div>
                <div class="route-card-stats">
                    <div><span>Distanz</span><strong>{{ number_format($opportunity['distance_km'], 0, ',', '.') }} km</strong></div>
                    <div><span>Nachfrage</span><strong>{{ number_format($opportunity['demand_index'], 2, ',', '.') }}</strong></div>
                    <div><span>Konkurrenz</span><strong>{{ $opportunity['competitor_count'] }}</strong></div>
                    <div><span>Flotte</span><strong>{{ $opportunity['reachable'] ? 'Erreichbar' : 'Keine Reichweite' }}</strong></div>
                </div>
                @if($opportunity['existing_route'])
                    <span class="badge">Bereits im Netz</span>
                @endif
            </a>
        @endforeach
    </div>
</section>
@endsection
