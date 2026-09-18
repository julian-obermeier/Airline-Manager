@extends('layouts.app')

@section('title', 'Markt & Konkurrenz · Airline Empire')
@section('eyebrow', 'MARKET INTELLIGENCE')
@section('heading', 'Markt & Konkurrenz')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">MÄRKTE</span>
        <strong>{{ $marketCount }}</strong>
        <small>aktive O&D-Märkte deiner Airline</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">UMKÄMPFT</span>
        <strong>{{ $contestedCount }}</strong>
        <small>Märkte mit mindestens einem Wettbewerber</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">KONKURRENTEN</span>
        <strong>{{ $competitorCount }}</strong>
        <small>unterschiedliche Airlines im direkten Wettbewerb</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">Ø MARKTANTEIL</span>
        <strong>{{ number_format($averageShare * 100, 1, ',', '.') }} %</strong>
        <small>über deine aktuell bedienten Märkte</small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">NETWORK POSITION</span>
            <h3>Marktposition deiner Routen</h3>
        </div>
        <span class="badge">7-Tage-Wettbewerbsfenster</span>
    </div>

    @if($routes->isEmpty())
        <div class="empty">Noch keine aktiven Routen vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Markt</th>
                    <th>Marktanteil</th>
                    <th>Position</th>
                    <th>Konkurrenten</th>
                    <th>Eigener Tarif</th>
                    <th>Markt Ø</th>
                    <th>Frequenz</th>
                    <th>Sitze / 7 Tage</th>
                    <th>Nachfragefaktor</th>
                    <th>Stärkster Konkurrent</th>
                </tr>
                </thead>
                <tbody>
                @foreach($routes as $route)
                    @php($snapshot = $snapshots->get($route->id))
                    <tr>
                        <td>
                            <strong>{{ $route->origin?->iata_code }} → {{ $route->destination?->iata_code }}</strong><br>
                            <span class="muted">{{ number_format((float) $route->distance_km, 0, ',', '.') }} km</span>
                        </td>
                        <td><strong>{{ number_format(((float) ($snapshot['market_share'] ?? 1)) * 100, 1, ',', '.') }} %</strong></td>
                        <td>#{{ $snapshot['rank'] ?? 1 }} / {{ $snapshot['participant_count'] ?? 1 }}</td>
                        <td>{{ $snapshot['competitor_count'] ?? 0 }}</td>
                        <td>{{ number_format(((int) ($snapshot['economy_fare_minor'] ?? 0)) / 100, 2, ',', '.') }} {{ $airline->base_currency }}</td>
                        <td>{{ number_format(((int) ($snapshot['market_average_economy_fare_minor'] ?? 0)) / 100, 2, ',', '.') }} {{ $airline->base_currency }}</td>
                        <td>{{ $snapshot['weekly_frequency'] ?? 0 }} / Woche</td>
                        <td>{{ number_format((int) ($snapshot['weekly_seat_capacity'] ?? 0), 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) ($snapshot['competition_multiplier'] ?? 1)) * 100, 1, ',', '.') }} %</td>
                        <td>
                            @if($snapshot['strongest_competitor'] ?? null)
                                <strong>{{ $snapshot['strongest_competitor']['airline_name'] }}</strong><br>
                                <span class="muted">{{ number_format(((float) $snapshot['strongest_competitor']['market_share']) * 100, 1, ',', '.') }} % Marktanteil</span>
                            @else
                                <span class="badge">Monopolmarkt</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

@foreach($routes as $route)
    @php($snapshot = $snapshots->get($route->id))
    <section class="card" style="margin-top:18px">
        <div class="section-title">
            <div>
                <span class="eyebrow">MARKET {{ $route->origin?->iata_code }} → {{ $route->destination?->iata_code }}</span>
                <h3>Wettbewerbsvergleich</h3>
            </div>
            <span class="badge">{{ $snapshot['participant_count'] ?? 1 }} Airlines</span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Airline</th>
                    <th>Marktanteil</th>
                    <th>Economy</th>
                    <th>Frequenz</th>
                    <th>Sitzangebot</th>
                    <th>Preis</th>
                    <th>Frequenz</th>
                    <th>Reputation</th>
                    <th>Service</th>
                    <th>Bekanntheit</th>
                    <th>Flugzeiten</th>
                    <th>Marketing</th>
                </tr>
                </thead>
                <tbody>
                @forelse(($snapshot['participants'] ?? []) as $participant)
                    <tr>
                        <td>
                            <strong>{{ $participant['airline_name'] }}</strong>
                            @if($participant['airline_id'] === $airline->id)
                                <span class="badge">Du</span>
                            @endif
                            <br><span class="muted">{{ $participant['airline_iata'] ?? '–' }} · #{{ $participant['rank'] }}</span>
                        </td>
                        <td><strong>{{ number_format(((float) $participant['market_share']) * 100, 1, ',', '.') }} %</strong></td>
                        <td>{{ number_format(((int) $participant['economy_fare_minor']) / 100, 2, ',', '.') }} {{ $airline->base_currency }}</td>
                        <td>{{ $participant['weekly_frequency'] }}</td>
                        <td>{{ number_format((int) $participant['weekly_seat_capacity'], 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.price', 1)) * 100, 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.frequency', 1)) * 100, 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.reputation', 1)) * 100, 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.service', 1)) * 100, 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.awareness', 1)) * 100, 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.schedule', 1)) * 100, 0, ',', '.') }}</td>
                        <td>{{ number_format(((float) data_get($participant, 'factors.marketing', 1)) * 100, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12">Keine Marktteilnehmer gefunden.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endforeach

<p class="footer-note">Marktanteile und Wettbewerbsscores sind Spielwerte. Sie werden aus Preis, Frequenz, Reputation, Servicequalität, Bekanntheit, Flugzeiten und aktivem Marketing berechnet und beeinflussen die tatsächliche Buchungsnachfrage.</p>
@endsection
