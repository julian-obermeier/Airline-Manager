<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaign extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'route_id',
        'name',
        'scope',
        'channel',
        'budget_minor',
        'currency',
        'starts_at',
        'ends_at',
        'status',
        'demand_boost',
        'awareness_gain',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'demand_boost' => 'decimal:4',
            'awareness_gain' => 'decimal:2',
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
