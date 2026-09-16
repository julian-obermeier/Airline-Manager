<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightSchedule extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'aircraft_id',
        'outbound_route_id',
        'return_route_id',
        'outbound_flight_number',
        'return_flight_number',
        'days_of_week',
        'starts_on',
        'departure_time',
        'turnaround_minutes',
        'generation_horizon_days',
        'status',
        'last_generated_on',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'starts_on' => 'date',
            'last_generated_on' => 'date',
            'settings' => 'array',
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

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function outboundRoute(): BelongsTo
    {
        return $this->belongsTo(AirlineRoute::class, 'outbound_route_id');
    }

    public function returnRoute(): BelongsTo
    {
        return $this->belongsTo(AirlineRoute::class, 'return_route_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class, 'flight_schedule_id');
    }
}
