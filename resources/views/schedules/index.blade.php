@extends('layouts.app')

@section('title', 'Flugpläne · Airline Empire')
@section('eyebrow', 'NETWORK PLANNING')
@section('heading', 'Wiederkehrende Flugpläne')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">PLÄNE</span>
        <strong>{{ $flightSchedules->count() }}</strong>
        <small>Gespeicherte Umläufe</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">AKTIV</span>
        <strong>{{ $flightSchedules->where('status', 'active')->count() }}</strong>
        <small>Automatisch fortgeschrieben</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">VORAUSPLANUNG</span>
        <strong>28 Tage</strong>
        <small>Rollierender Planungshorizont</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">TURNAROUND</span>
        <strong>Typabhängig</strong>
        <small>Mindestbodenzeit wird geprüft</small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">ROTATION BUILDER</span>
            <h3>Hin-/Rückflug-Umlauf anlegen</h3>
        </div>
        <span class="badge">Automatische Flugerzeugung</span>
    </div>

    @if($fleet->isEmpty() || $routes->count() < 2)
        <div class="empty">
            Für einen wiederkehrenden Umlauf benötigst du mindestens ein Flugzeug sowie eine Hin- und die passende Rückroute.
            Lege diese zuerst unter <a href="{{ route('operations.index') }}">Operations</a> an.
        </div>
    @else
        <form method="post" action="{{ route('schedules.store') }}" class="form-grid form-grid-4">
            @csrf

            <div class="field">
                <label for="aircraft_id">Flugzeug</label>
                <select id="aircraft_id" name="aircraft_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($fleet as $aircraft)
                        <option value="{{ $aircraft->id }}" @selected(old('aircraft_id') === $aircraft->id)>
                            {{ $aircraft->registration }} · {{ $aircraft->type->manufacturer }} {{ $aircraft->type->model }} · {{ $aircraft->currentAirport?->iata_code ?? 'unterwegs' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="outbound_route_id">Hinroute</label>
                <select id="outbound_route_id" name="outbound_route_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($routes as $route)
                        <option value="{{ $route->id }}" @selected(old('outbound_route_id') === $route->id)>
                            {{ $route->origin->iata_code }} → {{ $route->destination->iata_code }} · {{ intdiv($route->planned_block_minutes, 60) }}h {{ $route->planned_block_minutes % 60 }}m
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="return_route_id">Rückroute</label>
                <select id="return_route_id" name="return_route_id" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($routes as $route)
                        <option value="{{ $route->id }}" @selected(old('return_route_id') === $route->id)>
                            {{ $route->origin->iata_code }} → {{ $route->destination->iata_code }} · {{ intdiv($route->planned_block_minutes, 60) }}h {{ $route->planned_block_minutes % 60 }}m
                        </option>
                    @endforeach
                </select>
                <span class="help">Die Rückroute muss exakt zum Ausgangsflughafen zurückführen.</span>
            </div>

            <div class="field">
                <label for="starts_on">Startdatum</label>
                <input id="starts_on" type="date" name="starts_on" value="{{ old('starts_on', now()->addDay()->format('Y-m-d')) }}" required>
            </div>

            <div class="field">
                <label for="outbound_flight_number">Flugnummer Hinflug</label>
                <input id="outbound_flight_number" name="outbound_flight_number" maxlength="12" value="{{ old('outbound_flight_number', $airline->iata_code ? $airline->iata_code.'201' : '') }}" placeholder="z. B. AE201" required>
            </div>

            <div class="field">
                <label for="return_flight_number">Flugnummer Rückflug</label>
                <input id="return_flight_number" name="return_flight_number" maxlength="12" value="{{ old('return_flight_number', $airline->iata_code ? $airline->iata_code.'202' : '') }}" placeholder="z. B. AE202" required>
            </div>

            <div class="field">
                <label for="departure_time">Abflugzeit Hinflug</label>
                <input id="departure_time" type="time" name="departure_time" value="{{ old('departure_time', '08:00') }}" required>
            </div>

            <div class="field">
                <label for="turnaround_minutes">Turnaround am Ziel</label>
                <input id="turnaround_minutes" type="number" name="turnaround_minutes" min="20" max="240" step="5" value="{{ old('turnaround_minutes', 50) }}" required>
                <span class="help">Zu kurze Bodenzeiten werden typabhängig abgelehnt.</span>
            </div>

            <div class="field full">
                <label>Verkehrstage</label>
                <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;margin-top:6px">
                    @foreach([1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'] as $day => $label)
                        <label class="badge" style="display:flex;align-items:center;justify-content:center;gap:7px;padding:10px;cursor:pointer">
                            <input type="checkbox" name="days_of_week[]" value="{{ $day }}" @checked(in_array($day, old('days_of_week', [1,2,3,4,5]), true))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="field full">
                <button class="button primary" type="submit">Umlauf aktivieren und Flüge erzeugen</button>
            </div>
        </form>
    @endif
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">ACTIVE ROTATIONS</span>
            <h3>Gespeicherte Flugpläne</h3>
        </div>
        <span class="badge">{{ $flightSchedules->count() }} Pläne</span>
    </div>

    @if($flightSchedules->isEmpty())
        <div class="empty">Noch keine wiederkehrenden Flugpläne vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Umlauf</th>
                        <th>Flugzeug</th>
                        <th>Verkehrstage</th>
                        <th>Start</th>
                        <th>Turnaround</th>
                        <th>Zukünftige Flüge</th>
                        <th>Status</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($flightSchedules as $schedule)
                    <tr>
                        <td>
                            <strong>{{ $schedule->outbound_flight_number }} / {{ $schedule->return_flight_number }}</strong><br>
                            <span class="muted">
                                {{ $schedule->outboundRoute->origin->iata_code }} → {{ $schedule->outboundRoute->destination->iata_code }} → {{ $schedule->returnRoute->destination->iata_code }}
                            </span>
                        </td>
                        <td>{{ $schedule->aircraft->registration }}<br><span class="muted">{{ $schedule->aircraft->type->model }}</span></td>
                        <td>
                            @foreach([1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'] as $day => $label)
                                @if(in_array($day, $schedule->days_of_week ?? [], true))<span class="badge">{{ $label }}</span>@endif
                            @endforeach
                        </td>
                        <td>{{ $schedule->starts_on->format('d.m.Y') }}<br><span class="muted">{{ $schedule->departure_time }} Uhr</span></td>
                        <td>{{ $schedule->turnaround_minutes }} min</td>
                        <td>{{ $schedule->future_flights_count }}</td>
                        <td><span class="badge">{{ $schedule->status === 'active' ? 'Aktiv' : 'Pausiert' }}</span></td>
                        <td>
                            <form method="post" action="{{ route('schedules.toggle', $schedule) }}">
                                @csrf
                                @method('PATCH')
                                <button class="button ghost" type="submit">{{ $schedule->status === 'active' ? 'Pausieren' : 'Aktivieren' }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">
    Aktive Pläne werden bei jedem Simulationstick rollierend bis 28 Tage im Voraus ergänzt. Bestehende Flüge behalten ihre Tarif-, Buchungs- und Verspätungsdaten auch dann, wenn ein Plan später pausiert oder geändert wird.
</p>
@endsection
