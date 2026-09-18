<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteMarketPosition extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'route_id',
        'origin_airport_id',
        'destination_airport_id',
        'market_key',
        'competition_score',
        'market_share',
        'competition_multiplier',
        'weekly_frequency',
        'weekly_seat_capacity',
        'average_economy_fare_minor',
        'competitor_count',
        'calculated_at',
        'factors',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'competition_score' => 'decimal:4',
            'market_share' => 'decimal:6',
            'competition_multiplier' => 'decimal:4',
            'calculated_at' => 'datetime',
            'factors' => 'array',
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

    public function route(): BelongsTo
    {
        return $this->belongsTo(AirlineRoute::class, 'route_id');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }
}
