<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aircraft extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'aircraft';

    protected $fillable = [
        'world_id',
        'airline_id',
        'aircraft_type_id',
        'current_airport_id',
        'registration',
        'serial_number',
        'manufactured_on',
        'engine_variant',
        'flight_hours',
        'flight_cycles',
        'condition_percent',
        'status',
        'ownership_type',
        'acquisition_price_minor',
        'currency',
        'configuration',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_on' => 'date',
            'flight_hours' => 'decimal:2',
            'condition_percent' => 'decimal:2',
            'configuration' => 'array',
            'metadata' => 'array',
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

    public function type(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class, 'aircraft_type_id');
    }

    public function currentAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'current_airport_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }
}
