@extends('layouts.app')

@section('title', 'Maintenance · Airline Empire')
@section('eyebrow', 'TECHNICAL OPERATIONS')
@section('heading', 'Flottenwartung')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $aircraftStatusLabels = [
        'available' => 'Verfügbar',
        'in_flight' => 'Im Flug',
        'maintenance' => 'In Wartung',
        'grounded' => 'Gesperrt',
    ];
    $maintenanceStatusLabels = [
        'planned' => 'Geplant',
        'in_progress' => 'In Arbeit',
        'completed' => 'Abgeschlossen',
        'cancelled' => 'Storniert',
    ];
    $checkLabels = [
        'a_check' => 'A-Check',
        'c_check' => 'C-Check',
        'repair' => 'Technische Instandsetzung',
    ];
    $groundedCount = $fleet->where('status', 'grounded')->count();
    $maintenanceCount = $fleet->where('status', 'maintenance')->count();
    $dueCount = $fleet->filter(function ($aircraft) use ($snapshots) {
        $snapshot = $snapshots->get($aircraft->id, []);
        return data_get($snapshot, 'a_check.due') || data_get($snapshot, 'c_check.due') || data_get($snapshot, 'condition_warning');
    })->count();
@endphp

<section class="grid grid-4">
    <article class="card metric">
        <span class="eyebrow">TECHNICAL STATUS</span>
        <strong>{{ $fleet->count() - $groundedCount - $maintenanceCount }} / {{ $fleet->count() }}</strong>
        <small>Flugzeuge technisch verfügbar</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">WARTUNGSBEDARF</span>
        <strong>{{ $dueCount }}</strong>
        <small>Fällige oder auffällige Flugzeuge</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">GROUNDING</span>
        <strong>{{ $groundedCount }}</strong>
        <small>Automatisch gesperrte Flugzeuge</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong class="kpi-positive">{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Wartungen werden bei Abschluss gebucht</small>
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">MAINTENANCE PLANNER</span>
            <h3>Wartungsfenster planen</h3>
        </div>
        <span class="badge">Flugplan-Konflikte werden blockiert</span>
    </div>

    @if($fleet->isEmpty())
        <div class="empty">Für die Wartungsplanung wird mindestens ein Flugzeug benötigt.</div>
    @else
        <form method="post" action="{{ route('maintenance.store') }}" class="form-grid form-grid-4">
            @csrf
            <div class="field">
                <label for="maintenance_aircraft_id">Flugzeug</label>
                <select id="maintenance_aircraft_id" name="aircraft_id" data-searchable data-search-placeholder="Kennzeichen oder Flugzeugtyp suchen…" required>
                    <option value="">Bitte auswählen</option>
                    @foreach($fleet as $aircraft)
                        <option value="{{ $aircraft->id }}" @selected(old('aircraft_id') === $aircraft->id)>
                            {{ $aircraft->registration }} · {{ $aircraft->type->manufacturer }} {{ $aircraft->type->model }} · {{ number_format((float) $aircraft->condition_percent, 1, ',', '.') }} %
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="check_type">Maßnahme</label>
                <select id="check_type" name="check_type" required>
                    <option value="a_check" @selected(old('check_type') === 'a_check')>A-Check</option>
                    <option value="c_check" @selected(old('check_type') === 'c_check')>C-Check</option>
                    <option value="repair" @selected(old('check_type') === 'repair')>Technische Instandsetzung</option>
                </select>
            </div>
            <div class="field">
                <label for="planned_start_at">Geplanter Beginn</label>
                <input id="planned_start_at" type="datetime-local" name="planned_start_at" value="{{ old('planned_start_at') }}" required>
            </div>
            <div class="field" style="align-self:end">
                <button class="button primary" type="submit">Wartung verbindlich planen</button>
            </div>
            <div class="field full">
                <span class="help">Dauer und Kosten werden nach Flugzeuggröße und Maßnahme berechnet. Ein Wartungsfenster kann nicht über bestehende Flüge oder eine andere Wartung gelegt werden.</span>
            </div>
        </form>
    @endif
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">FLEET HEALTH</span>
            <h3>Technischer Flottenstatus</h3>
        </div>
        <span class="badge">A-/C-Check-Intervalle aktiv</span>
    </div>

    @if($fleet->isEmpty())
        <div class="empty">Noch keine Flugzeuge vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Flugzeug</th>
                    <th>Zustand</th>
                    <th>A-Check</th>
                    <th>C-Check</th>
                    <th>Empfehlung</th>
                    <th>Kostenindikator</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($fleet as $aircraft)
                    @php
                        $snapshot = $snapshots->get($aircraft->id);
                        $aCheck = $snapshot['a_check'];
                        $cCheck = $snapshot['c_check'];
                        $recommended = $snapshot['recommended_check'];
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $aircraft->registration }}</strong><br>
                            <span class="muted">{{ $aircraft->type->manufacturer }} {{ $aircraft->type->model }} · {{ $aircraft->currentAirport?->iata_code ?? '–' }}</span>
                        </td>
                        <td>
                            <strong>{{ number_format((float) $aircraft->condition_percent, 1, ',', '.') }} %</strong><br>
                            <span class="muted">{{ number_format((float) $aircraft->flight_hours, 1, ',', '.') }} h · {{ number_format((int) $aircraft->flight_cycles, 0, ',', '.') }} Zyklen</span>
                        </td>
                        <td>
                            <strong>{{ number_format((float) $aCheck['progress_percent'], 0, ',', '.') }} %</strong><br>
                            <span class="muted">
                                @if($aCheck['due'])
                                    Fällig · {{ number_format(abs((float) $aCheck['remaining_hours']), 0, ',', '.') }} h / {{ number_format(abs((int) $aCheck['remaining_cycles']), 0, ',', '.') }} Zyklen {{ $aCheck['overdue_hard'] ? 'über Grenzwert' : 'über Intervall' }}
                                @else
                                    Noch {{ number_format(max(0, (float) $aCheck['remaining_hours']), 0, ',', '.') }} h oder {{ number_format(max(0, (int) $aCheck['remaining_cycles']), 0, ',', '.') }} Zyklen
                                @endif
                            </span>
                        </td>
                        <td>
                            <strong>{{ number_format((float) $cCheck['progress_percent'], 0, ',', '.') }} %</strong><br>
                            <span class="muted">
                                @if($cCheck['due'])
                                    Fällig · {{ number_format(abs((float) $cCheck['remaining_hours']), 0, ',', '.') }} h / {{ number_format(abs((int) $cCheck['remaining_cycles']), 0, ',', '.') }} Zyklen {{ $cCheck['overdue_hard'] ? 'über Grenzwert' : 'über Intervall' }}
                                @else
                                    Noch {{ number_format(max(0, (float) $cCheck['remaining_hours']), 0, ',', '.') }} h oder {{ number_format(max(0, (int) $cCheck['remaining_cycles']), 0, ',', '.') }} Zyklen
                                @endif
                            </span>
                        </td>
                        <td>
                            @if($recommended)
                                <span class="badge">{{ $checkLabels[$recommended] ?? $recommended }}</span>
                            @else
                                <span class="muted">Keine Maßnahme fällig</span>
                            @endif
                        </td>
                        <td>
                            <span class="muted">A {{ number_format($snapshot['quotes']['a_check']['cost_minor'] / 100, 0, ',', '.') }} €</span><br>
                            <span class="muted">C {{ number_format($snapshot['quotes']['c_check']['cost_minor'] / 100, 0, ',', '.') }} €</span><br>
                            <span class="muted">Repair {{ number_format($snapshot['quotes']['repair']['cost_minor'] / 100, 0, ',', '.') }} €</span>
                        </td>
                        <td><span class="badge">{{ $aircraftStatusLabels[$aircraft->status] ?? ucfirst($aircraft->status) }}</span></td>
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
            <span class="eyebrow">WORK ORDERS</span>
            <h3>Wartungsaufträge</h3>
        </div>
        <span class="badge">{{ $events->count() }} Einträge</span>
    </div>

    @if($events->isEmpty())
        <div class="empty">Noch keine Wartungsaufträge vorhanden.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Flugzeug</th><th>Maßnahme</th><th>Beginn</th><th>Ende</th><th>Kosten</th><th>Status</th><th>Aktion</th></tr></thead>
                <tbody>
                @foreach($events as $event)
                    <tr>
                        <td><strong>{{ $event->aircraft?->registration ?? '–' }}</strong></td>
                        <td>{{ $checkLabels[$event->check_type] ?? $event->check_type }}</td>
                        <td>{{ $event->planned_start_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td>
                            {{ $event->planned_end_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
                            @if($event->actual_end_at)
                                <br><span class="muted">Tatsächlich {{ $event->actual_end_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                            @endif
                        </td>
                        <td>{{ number_format($event->cost_minor / 100, 2, ',', '.') }} {{ $event->currency }}</td>
                        <td><span class="badge">{{ $maintenanceStatusLabels[$event->status] ?? ucfirst($event->status) }}</span></td>
                        <td>
                            @if($event->status === 'planned')
                                <form method="post" action="{{ route('maintenance.cancel', $event) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button class="button ghost" type="submit">Stornieren</button>
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

<p class="footer-note">A- und C-Checks werden anhand von Flugstunden und Zyklen überwacht. Wird ein Sicherheits-Grenzwert überschritten oder fällt der technische Zustand unter {{ number_format((float) config('maintenance.condition_grounding_percent', 70), 0, ',', '.') }} %, sperrt das System das Flugzeug automatisch für weitere Einsätze.</p>
@endsection
