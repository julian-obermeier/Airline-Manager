@extends('layouts.app')

@section('title', 'Marketing & Reputation · Airline Empire')
@section('eyebrow', 'COMMERCIAL STRATEGY')
@section('heading', 'Marketing & Reputation')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $statusLabels = [
        'active' => 'Aktiv',
        'completed' => 'Beendet',
        'cancelled' => 'Abgebrochen',
    ];
@endphp

<section class="grid grid-4">
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="marketing" :size="19" /></div>
        <span class="eyebrow">BEKANNTHEIT</span>
        <strong>{{ number_format((float) $profile->awareness_score, 1, ',', '.') }} %</strong>
        <small>Airline-weite Markenbekanntheit</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="status" :size="19" /></div>
        <span class="eyebrow">REPUTATION</span>
        <strong>{{ number_format((float) $profile->reputation_score, 1, ',', '.') }} %</strong>
        <small>langfristiger Vertrauenswert</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="crew" :size="19" /></div>
        <span class="eyebrow">ZUFRIEDENHEIT</span>
        <strong>{{ number_format((float) $profile->satisfaction_score, 1, ',', '.') }} %</strong>
        <small>beeinflusst durch Service & Pünktlichkeit</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="finance" :size="19" /></div>
        <span class="eyebrow">MARKENWERT</span>
        <strong>{{ number_format($profile->brand_value_minor / 100, 0, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Spielwert aus Bekanntheit, Reputation und Netzwerk</small>
    </article>
</section>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">CAMPAIGN BUILDER</span>
                <h3>Kampagne starten</h3>
            </div>
            <span class="badge">{{ number_format($cashBalanceMinor / 100, 0, ',', '.') }} {{ $airline->base_currency }} Cash</span>
        </div>

        <form method="post" action="{{ route('marketing.store') }}" class="form-grid">
            @csrf
            <div class="field full">
                <label for="name">Kampagnenname</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required>
            </div>
            <div class="field">
                <label for="scope">Ziel</label>
                <select id="scope" name="scope" required>
                    <option value="brand" @selected(old('scope', 'brand') === 'brand')>Marke / gesamte Airline</option>
                    <option value="route" @selected(old('scope') === 'route')>Einzelne Route</option>
                </select>
            </div>
            <div class="field">
                <label for="route_id">Route</label>
                <select id="route_id" name="route_id" data-searchable data-search-placeholder="Route suchen…">
                    <option value="">Nur bei Streckenkampagne</option>
                    @foreach($routes as $route)
                        <option value="{{ $route->id }}" @selected(old('route_id') === $route->id)>
                            {{ $route->origin?->iata_code }} → {{ $route->destination?->iata_code }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="channel">Kanal</label>
                <select id="channel" name="channel" required>
                    @foreach($channels as $key => $channel)
                        <option value="{{ $key }}" @selected(old('channel') === $key)>{{ $channel['label'] ?? $key }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="budget">Budget</label>
                <input id="budget" type="number" name="budget" min="10000" max="5000000" step="5000" value="{{ old('budget', 100000) }}" required>
            </div>
            <div class="field">
                <label for="duration_days">Laufzeit</label>
                <input id="duration_days" type="number" name="duration_days" min="1" max="90" value="{{ old('duration_days', 14) }}" required>
            </div>
            <div class="field full">
                <button class="button primary" type="submit">Kampagne starten & Budget buchen</button>
            </div>
        </form>

        <p class="footer-note">Das Budget wird beim Start vollständig als Marketingaufwand verbucht. Ein vorzeitiger Abbruch erzeugt keine Rückerstattung.</p>
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">SERVICE SIGNAL</span>
                <h3>Commercial Health</h3>
            </div>
            <span class="badge">{{ $profile->completed_flights }} Flüge bewertet</span>
        </div>

        <div class="stack">
            <p><strong>Servicequalität:</strong> {{ number_format((float) $profile->service_quality_score, 1, ',', '.') }} %</p>
            <p><strong>Abgeschlossene Flüge:</strong> {{ $profile->completed_flights }}</p>
            <p><strong>Annullierte Flüge:</strong> {{ $profile->cancelled_flights }}</p>
            <p><strong>Wirkung:</strong> Bekanntheit, Reputation, Zufriedenheit und Streckenbekanntheit fließen direkt in die Buchungsnachfrage ein.</p>
        </div>
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">ROUTE AWARENESS</span>
            <h3>Streckenbekanntheit</h3>
        </div>
        <span class="badge">{{ $routes->count() }} Routen</span>
    </div>

    @if($routes->isEmpty())
        <div class="empty">Noch keine Routen vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>Route</th><th>Bekanntheit</th><th>Zufriedenheit</th><th>Historische Auslastung</th><th>Flüge</th><th>Annullierungen</th></tr>
                </thead>
                <tbody>
                @foreach($routes as $route)
                    @php($metric = $routeMetrics->get($route->id))
                    <tr>
                        <td><strong>{{ $route->origin?->iata_code }} → {{ $route->destination?->iata_code }}</strong></td>
                        <td>{{ number_format((float) $metric->awareness_score, 1, ',', '.') }} %</td>
                        <td>{{ number_format((float) $metric->satisfaction_score, 1, ',', '.') }} %</td>
                        <td>{{ number_format(((float) $metric->historical_load_factor) * 100, 1, ',', '.') }} %</td>
                        <td>{{ $metric->completed_flights }}</td>
                        <td>{{ $metric->cancelled_flights }}</td>
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
            <span class="eyebrow">CAMPAIGNS</span>
            <h3>Kampagnenhistorie</h3>
        </div>
        <span class="badge">{{ $campaigns->where('status', 'active')->count() }} aktiv</span>
    </div>

    @if($campaigns->isEmpty())
        <div class="empty">Noch keine Marketingkampagne gestartet.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>Kampagne</th><th>Ziel</th><th>Kanal</th><th>Budget</th><th>Nachfragebonus</th><th>Laufzeit</th><th>Status</th><th>Aktion</th></tr>
                </thead>
                <tbody>
                @foreach($campaigns as $campaign)
                    <tr>
                        <td><strong>{{ $campaign->name }}</strong></td>
                        <td>
                            @if($campaign->scope === 'route')
                                {{ $campaign->route?->origin?->iata_code }} → {{ $campaign->route?->destination?->iata_code }}
                            @else
                                Gesamtmarke
                            @endif
                        </td>
                        <td>{{ data_get($channels, $campaign->channel.'.label', $campaign->channel) }}</td>
                        <td>{{ number_format($campaign->budget_minor / 100, 2, ',', '.') }} {{ $campaign->currency }}</td>
                        <td>+{{ number_format(((float) $campaign->demand_boost) * 100, 1, ',', '.') }} %</td>
                        <td>{{ $campaign->starts_at->timezone('Europe/Berlin')->format('d.m.Y') }} – {{ $campaign->ends_at->timezone('Europe/Berlin')->format('d.m.Y') }}</td>
                        <td><span class="badge">{{ $statusLabels[$campaign->status] ?? $campaign->status }}</span></td>
                        <td>
                            @if($campaign->status === 'active')
                                <form method="post" action="{{ route('marketing.cancel', $campaign) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button class="button ghost" type="submit">Beenden</button>
                                </form>
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Alle Marketing-, Marken- und Reputationswerte sind Spielparameter der Simulation und keine realen Marktkennzahlen.</p>
@endsection
