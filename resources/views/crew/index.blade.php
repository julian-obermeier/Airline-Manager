@extends('layouts.app')

@section('title', 'Personal & Crew · Airline Empire')
@section('eyebrow', 'CREW OPERATIONS')
@section('heading', 'Personal & Crew')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $roleLabels = [
        'captain' => 'Captain',
        'first_officer' => 'First Officer',
        'cabin_crew' => 'Cabin Crew',
    ];
    $statusLabels = [
        'active' => 'Aktiv',
        'inactive' => 'Ausgeschieden',
    ];
@endphp

<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">AKTIVES PERSONAL</span>
        <strong>{{ $activeCount }}</strong>
        <small>{{ $captainCount }} Captains · {{ $firstOfficerCount }} First Officers · {{ $cabinCrewCount }} Cabin Crew</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">MONATLICHE PAYROLL</span>
        <strong>{{ number_format($monthlyPayrollMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Aktuelle Brutto-Spielkosten pro Monat</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">CREW-BEDARF</span>
        <strong>{{ $understaffedFlights }}</strong>
        <small>kommende Flüge aktuell nicht vollständig besetzt</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">DIENSTREGEL</span>
        <strong>{{ intdiv((int) config('crew.default_max_duty_minutes_day', 780), 60) }} h</strong>
        <small>max. simulierte Tagesdienstzeit · {{ intdiv((int) config('crew.default_min_rest_minutes', 660), 60) }} h Mindestruhe</small>
    </article>
</section>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">RECRUITING</span>
                <h3>Mitarbeiter einstellen</h3>
            </div>
            <span class="badge">Direkteinstellung</span>
        </div>

        <form method="post" action="{{ route('crew.store') }}" class="form-grid">
            @csrf
            <div class="field full">
                <div class="finance-score">
                    <div class="metric-icon" style="margin:0"><x-icon name="crew" :size="19" /></div>
                    <div>
                        <strong>Fiktive Personalakte wird automatisch erstellt</strong>
                        <div class="muted" style="margin-top:3px">Vor- und Nachname sowie Personalnummer generiert Airline Empire automatisch.</div>
                    </div>
                </div>
            </div>
            <div class="field">
                <label for="role">Funktion</label>
                <select id="role" name="role" required>
                    <option value="captain" @selected(old('role') === 'captain')>Captain</option>
                    <option value="first_officer" @selected(old('role', 'first_officer') === 'first_officer')>First Officer</option>
                    <option value="cabin_crew" @selected(old('role') === 'cabin_crew')>Cabin Crew</option>
                </select>
            </div>
            <div class="field">
                <label for="monthly_salary">Monatsgehalt</label>
                <input id="monthly_salary" type="number" name="monthly_salary" min="1000" max="50000" step="50" value="{{ old('monthly_salary', '6500') }}" required>
            </div>
            <div class="field">
                <label for="base_airport_id">Crew-Basis</label>
                <select id="base_airport_id" name="base_airport_id" data-searchable data-search-placeholder="Crew-Basis suchen…" required>
                    <x-airport-options :airports="$airports" :selected="old('base_airport_id', $airline->home_airport_id)" />
                </select>
                <span class="help">Neue Crew startet physisch an dieser Basis.</span>
            </div>
            <div class="field">
                <label for="aircraft_type_id">Initiales Type Rating</label>
                <select id="aircraft_type_id" name="aircraft_type_id" data-searchable data-search-placeholder="Flugzeugmuster suchen…">
                    <option value="">Kein Rating</option>
                    @foreach($aircraftTypes as $type)
                        <option value="{{ $type->id }}" @selected(old('aircraft_type_id') === $type->id)>
                            {{ $type->manufacturer }} {{ $type->model }} {{ $type->variant }}
                        </option>
                    @endforeach
                </select>
                <span class="help">Für Captain und First Officer zwingend; Cabin Crew kann ohne Type Rating eingestellt werden.</span>
            </div>
            <div class="field full">
                <button class="button primary" type="submit"><x-icon name="crew" :size="17" /> Fiktiven Mitarbeiter einstellen</button>
            </div>
        </form>

        <p class="footer-note">Die Dienst- und Ruhezeitregeln sind Spielregeln der Simulation und keine Abbildung einer konkreten gesetzlichen FTL-Regelung.</p>
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">QUALIFICATIONS</span>
                <h3>Type Rating ergänzen</h3>
            </div>
            <span class="badge">Pilotenqualifikation</span>
        </div>

        @if($crew->where('status', 'active')->isEmpty())
            <div class="empty">Noch kein aktives Personal vorhanden.</div>
        @else
            <form method="post" id="qualification-form" action="{{ route('crew.qualifications.store', $crew->where('status', 'active')->first()) }}" class="form-grid">
                @csrf
                <div class="field full">
                    <label for="qualification_member">Mitarbeiter</label>
                    <select id="qualification_member" required
                            onchange="document.getElementById('qualification-form').action='{{ url('/crew') }}/'+this.value+'/qualifications'">
                        @foreach($crew->where('status', 'active') as $member)
                            <option value="{{ $member->id }}">{{ $member->employee_number }} · {{ $member->full_name }} · {{ $roleLabels[$member->role] ?? $member->role }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="qualification_aircraft_type_id">Flugzeugmuster</label>
                    <select id="qualification_aircraft_type_id" name="aircraft_type_id" data-searchable data-search-placeholder="Type Rating suchen…" required>
                        @foreach($aircraftTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->manufacturer }} {{ $type->model }} {{ $type->variant }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="valid_until">Gültig bis <span class="muted">(optional)</span></label>
                    <input id="valid_until" type="date" name="valid_until">
                </div>
                <div class="field full">
                    <button class="button" type="submit">Type Rating speichern</button>
                </div>
            </form>
        @endif
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">CREW ROSTER</span>
            <h3>Personalbestand</h3>
        </div>
        <span class="badge">{{ $crew->count() }} Datensätze</span>
    </div>

    @if($crew->isEmpty())
        <div class="empty">Noch kein Personal eingestellt.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Mitarbeiter</th>
                    <th>Funktion</th>
                    <th>Basis / Standort</th>
                    <th>Type Ratings</th>
                    <th>Gehalt</th>
                    <th>Kommende Einsätze</th>
                    <th>Status</th>
                    <th>Aktion</th>
                </tr>
                </thead>
                <tbody>
                @foreach($crew as $member)
                    <tr>
                        <td>
                            <strong>{{ $member->employee_number }}</strong><br>
                            {{ $member->full_name }}
                        </td>
                        <td>{{ $roleLabels[$member->role] ?? $member->role }}</td>
                        <td>
                            <strong>{{ $member->currentAirport?->iata_code ?? '–' }}</strong>
                            <br><span class="muted">Basis {{ $member->homeAirport?->iata_code ?? '–' }}</span>
                        </td>
                        <td>
                            @forelse($member->qualifications->where('status', 'active') as $qualification)
                                <span class="badge">{{ $qualification->aircraftType?->icao_type_code ?? $qualification->aircraftType?->model ?? 'Rating' }}</span>
                            @empty
                                <span class="muted">Keine</span>
                            @endforelse
                        </td>
                        <td>{{ number_format($member->monthly_salary_minor / 100, 2, ',', '.') }} {{ $member->currency }}</td>
                        <td>{{ $member->upcoming_assignments_count }}</td>
                        <td><span class="badge">{{ $statusLabels[$member->status] ?? $member->status }}</span></td>
                        <td>
                            @if($member->status === 'active')
                                <form method="post" action="{{ route('crew.terminate', $member) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button class="button ghost" type="submit">Ausscheiden</button>
                                </form>
                            @else
                                <span class="muted">{{ $member->terminated_at?->timezone('Europe/Berlin')->format('d.m.Y') ?? '–' }}</span>
                            @endif
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
        <div>
            <span class="eyebrow">CREW COVERAGE</span>
            <h3>Kommende Flüge</h3>
        </div>
        <span class="badge">{{ $upcomingFlights->count() }} geprüft</span>
    </div>

    @if($upcomingFlights->isEmpty())
        <div class="empty">Keine kommenden Flüge vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Flug</th>
                    <th>Route</th>
                    <th>Abflug</th>
                    <th>Flugzeug</th>
                    <th>Captain</th>
                    <th>First Officer</th>
                    <th>Cabin</th>
                    <th>Besetzung</th>
                </tr>
                </thead>
                <tbody>
                @foreach($upcomingFlights as $flight)
                    @php($snapshot = $staffing->get($flight->id))
                    <tr>
                        <td><strong>{{ $flight->flight_number }}</strong></td>
                        <td>{{ $flight->route?->origin?->iata_code }} → {{ $flight->route?->destination?->iata_code }}</td>
                        <td>{{ $flight->scheduled_departure_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>{{ $flight->aircraft?->registration ?? '–' }}</td>
                        <td>{{ $snapshot['assigned']['captain'] }} / {{ $snapshot['requirements']['captain'] }}</td>
                        <td>{{ $snapshot['assigned']['first_officer'] }} / {{ $snapshot['requirements']['first_officer'] }}</td>
                        <td>{{ $snapshot['assigned']['cabin_crew'] }} / {{ $snapshot['requirements']['cabin_crew'] }}</td>
                        <td>
                            @if($snapshot['complete'])
                                <span class="badge">Vollständig</span>
                            @else
                                <span class="badge">Fehlen {{ $snapshot['missing']['total'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Piloten benötigen für das eingesetzte Flugzeugmuster ein gültiges Type Rating. Die Crew-Engine berücksichtigt Standort, bereits geplante Einsätze, simulierte Tagesdienstzeit und Ruhephasen. Personalkosten werden monatlich über das bestehende Ledger gebucht.</p>
@endsection
