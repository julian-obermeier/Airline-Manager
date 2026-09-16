<?php

namespace App\Services\Operations;

use App\Models\Aircraft;
use App\Models\AirlineRoute;
use App\Models\Flight;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AircraftPositionService
{
    public function assertCanScheduleLeg(
        Aircraft $aircraft,
        AirlineRoute $route,
        Carbon $departure,
        Carbon $arrival,
        array $excludedFlightIds = [],
    ): void {
        $aircraft->loadMissing(['currentAirport', 'type']);
        $route->loadMissing(['origin', 'destination']);

        $previous = $this->previousFlight($aircraft, $departure, $excludedFlightIds);
        $expectedOriginId = $previous?->route?->destination_airport_id ?? $aircraft->current_airport_id;
        $expectedOriginCode = $previous?->route?->destination?->iata_code
            ?? $aircraft->currentAirport?->iata_code
            ?? 'unbekannt';

        if (! $expectedOriginId) {
            throw ValidationException::withMessages([
                'aircraft_id' => 'Für dieses Flugzeug ist zum geplanten Abflugzeitpunkt kein verfügbarer Flughafen bestimmbar.',
            ]);
        }

        if ($expectedOriginId !== $route->origin_airport_id) {
            throw ValidationException::withMessages([
                'aircraft_id' => 'Dieses Flugzeug befindet sich zum geplanten Abflug bei '.$expectedOriginCode.'. Der Flug muss dort starten; gewählt wurde '.$route->origin?->iata_code.'.',
            ]);
        }

        if ($previous) {
            $previousArrival = $previous->actual_arrival_at
                ? $previous->actual_arrival_at->copy()
                : $previous->scheduled_arrival_at->copy()->addMinutes((int) $previous->delay_minutes);
            $earliestDeparture = $previousArrival->copy()->addMinutes($this->minimumTurnaroundMinutes($aircraft));

            if ($earliestDeparture->greaterThan($departure)) {
                throw ValidationException::withMessages([
                    'scheduled_departure_at' => 'Das Flugzeug erreicht '.$expectedOriginCode.' erst um '.$previousArrival->format('d.m.Y H:i').' Uhr. Einschließlich Turnaround ist der früheste nächste Abflug um '.$earliestDeparture->format('d.m.Y H:i').' Uhr möglich.',
                ]);
            }
        }

        $next = $this->nextFlight($aircraft, $departure, $excludedFlightIds);

        if ($next) {
            $next->loadMissing('route.origin');
            $requiredNextOriginId = $next->route?->origin_airport_id;

            if ($requiredNextOriginId && $requiredNextOriginId !== $route->destination_airport_id) {
                throw ValidationException::withMessages([
                    'aircraft_id' => 'Nach diesem Flug wäre das Flugzeug in '.$route->destination?->iata_code.', der bereits geplante Folgeflug startet jedoch in '.$next->route?->origin?->iata_code.'. Der Umlauf wäre dadurch unterbrochen.',
                ]);
            }

            $nextDeparture = $next->scheduled_departure_at->copy()->addMinutes((int) $next->delay_minutes);
            $readyAt = $arrival->copy()->addMinutes($this->minimumTurnaroundMinutes($aircraft));

            if ($readyAt->greaterThan($nextDeparture)) {
                throw ValidationException::withMessages([
                    'scheduled_departure_at' => 'Nach Landung und Mindest-Turnaround wäre das Flugzeug erst um '.$readyAt->format('d.m.Y H:i').' Uhr wieder einsatzbereit. Der bereits geplante Folgeflug startet früher.',
                ]);
            }
        }
    }

    public function plannedOriginAirportId(Aircraft $aircraft, Carbon $departure, array $excludedFlightIds = []): ?string
    {
        $previous = $this->previousFlight($aircraft, $departure, $excludedFlightIds);

        return $previous?->route?->destination_airport_id ?? $aircraft->current_airport_id;
    }

    public function minimumTurnaroundMinutes(Aircraft $aircraft): int
    {
        $aircraft->loadMissing('type');

        $configured = (int) data_get($aircraft->type?->technical_data, 'minimum_turnaround_minutes', 0);
        if ($configured > 0) {
            return $configured;
        }

        $seats = max(1, (int) data_get($aircraft->configuration, 'seats', $aircraft->type?->typical_seats ?? 1));

        return match (true) {
            $seats <= 120 => 35,
            $seats <= 160 => 40,
            $seats <= 200 => 45,
            $seats <= 260 => 55,
            default => 75,
        };
    }

    private function previousFlight(Aircraft $aircraft, Carbon $departure, array $excludedFlightIds): ?Flight
    {
        return Flight::query()
            ->with(['route.destination'])
            ->where('aircraft_id', $aircraft->id)
            ->whereNotIn('status', ['cancelled'])
            ->when($excludedFlightIds !== [], fn ($query) => $query->whereNotIn('id', $excludedFlightIds))
            ->where('scheduled_departure_at', '<', $departure)
            ->orderByDesc('scheduled_departure_at')
            ->first();
    }

    private function nextFlight(Aircraft $aircraft, Carbon $departure, array $excludedFlightIds): ?Flight
    {
        return Flight::query()
            ->with(['route.origin'])
            ->where('aircraft_id', $aircraft->id)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->when($excludedFlightIds !== [], fn ($query) => $query->whereNotIn('id', $excludedFlightIds))
            ->where('scheduled_departure_at', '>=', $departure)
            ->orderBy('scheduled_departure_at')
            ->first();
    }
}
