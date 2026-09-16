<?php

namespace App\Services\Operations;

use App\Models\Flight;
use App\Models\World;
use Carbon\Carbon;

class FlightLocationGuardService
{
    public function __construct(private readonly AircraftPositionService $positions)
    {
    }

    public function guardBeforeTick(?Carbon $realNow = null): array
    {
        $realNow ??= now();
        $summary = [
            'checked' => 0,
            'cancelled_location' => 0,
        ];

        World::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (World $world) use ($realNow, &$summary): void {
                $targetSimulationNow = $this->targetSimulationTime($world, $realNow);

                Flight::query()
                    ->with(['route.origin', 'route.destination', 'aircraft.currentAirport'])
                    ->where('world_id', $world->id)
                    ->whereIn('status', ['scheduled', 'boarding'])
                    ->where('scheduled_departure_at', '<=', $targetSimulationNow)
                    ->orderBy('scheduled_departure_at')
                    ->each(function (Flight $flight) use (&$summary): void {
                        $summary['checked']++;

                        if (! $flight->aircraft || ! $flight->route) {
                            $this->cancel($flight, 'Flugzeug oder Route fehlt.');
                            $summary['cancelled_location']++;
                            return;
                        }

                        $plannedDeparture = $flight->scheduled_departure_at->copy()->addMinutes((int) $flight->delay_minutes);
                        $expectedAirportId = $this->positions->plannedOriginAirportId(
                            $flight->aircraft,
                            $plannedDeparture,
                            [$flight->id],
                        );

                        if ($expectedAirportId !== $flight->route->origin_airport_id) {
                            $expectedCode = $flight->aircraft->currentAirport?->iata_code ?? 'anderem Standort';
                            $requiredCode = $flight->route->origin?->iata_code ?? 'unbekannt';
                            $this->cancel(
                                $flight,
                                'Flugzeug steht zum Abflug nicht am Startflughafen '.$requiredCode.' (erwarteter Standort: '.$expectedCode.').',
                            );
                            $summary['cancelled_location']++;
                        }
                    });
            });

        return $summary;
    }

    private function targetSimulationTime(World $world, Carbon $realNow): Carbon
    {
        if (! $world->last_simulation_tick_at) {
            return $realNow->copy();
        }

        $elapsedSeconds = max(0, $world->last_simulation_tick_at->diffInSeconds($realNow, false));
        $speed = max(0.01, (float) $world->speed_multiplier);

        return ($world->simulated_at ?? $realNow)
            ->copy()
            ->addSeconds((int) round($elapsedSeconds * $speed));
    }

    private function cancel(Flight $flight, string $reason): void
    {
        $data = $flight->operational_data ?? [];
        $data['operations'] = array_merge($data['operations'] ?? [], [
            'cancelled_at' => now()->toIso8601String(),
            'cancellation_code' => 'aircraft_wrong_airport',
            'cancellation_reason' => $reason,
        ]);

        $flight->forceFill([
            'status' => 'cancelled',
            'operational_data' => $data,
        ])->save();
    }
}
