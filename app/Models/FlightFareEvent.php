<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightFareEvent extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'flight_id',
        'cabin',
        'bucket_code',
        'previous_fare_minor',
        'new_fare_minor',
        'base_fare_minor',
        'load_factor',
        'booking_progress',
        'competition_multiplier',
        'calculated_at',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'load_factor' => 'decimal:4',
            'booking_progress' => 'decimal:4',
            'competition_multiplier' => 'decimal:4',
            'calculated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
