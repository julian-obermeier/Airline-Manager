<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteCommercialMetric extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'route_id',
        'awareness_score',
        'satisfaction_score',
        'historical_load_factor',
        'completed_flights',
        'cancelled_flights',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'awareness_score' => 'decimal:2',
            'satisfaction_score' => 'decimal:2',
            'historical_load_factor' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(AirlineRoute::class, 'route_id');
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
