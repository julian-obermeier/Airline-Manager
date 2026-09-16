<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftMaintenanceEvent extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'aircraft_id',
        'check_type',
        'status',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'cost_minor',
        'currency',
        'condition_before',
        'condition_after',
        'flight_hours_snapshot',
        'flight_cycles_snapshot',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'condition_before' => 'decimal:2',
            'condition_after' => 'decimal:2',
            'flight_hours_snapshot' => 'decimal:2',
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

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }
}
