<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftMarketOffer extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id', 'aircraft_type_id', 'location_airport_id', 'serial_number', 'manufactured_on',
        'flight_hours', 'flight_cycles', 'condition_percent', 'price_minor', 'currency', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_on' => 'date',
            'flight_hours' => 'decimal:2',
            'condition_percent' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function world(): BelongsTo { return $this->belongsTo(World::class); }
    public function type(): BelongsTo { return $this->belongsTo(AircraftType::class, 'aircraft_type_id'); }
    public function locationAirport(): BelongsTo { return $this->belongsTo(Airport::class, 'location_airport_id'); }
}
