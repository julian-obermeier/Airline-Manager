<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'route_id',
        'aircraft_id',
        'flight_schedule_id',
        'flight_number',
        'scheduled_departure_at',
        'scheduled_arrival_at',
        'actual_departure_at',
        'actual_arrival_at',
        'status',
        'delay_minutes',
        'passengers_booked',
        'cargo_kg_booked',
        'operational_data',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_departure_at' => 'datetime',
            'scheduled_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'operational_data' => 'array',
        ];
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(AirlineRoute::class, 'route_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function flightSchedule(): BelongsTo
    {
        return $this->belongsTo(FlightSchedule::class);
    }

    public function crewAssignments(): HasMany
    {
        return $this->hasMany(FlightCrewAssignment::class);
    }

    public function slotReservations(): HasMany
    {
        return $this->hasMany(AirportSlotReservation::class);
    }

    public function fareEvents(): HasMany
    {
        return $this->hasMany(FlightFareEvent::class);
    }
}
