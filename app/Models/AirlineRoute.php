<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AirlineRoute extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'routes';

    protected $fillable = [
        'world_id',
        'airline_id',
        'origin_airport_id',
        'destination_airport_id',
        'distance_km',
        'planned_block_minutes',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
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

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function commercialMetric(): HasOne
    {
        return $this->hasOne(RouteCommercialMetric::class, 'route_id');
    }

    public function marketingCampaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class, 'route_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class, 'route_id');
    }
}
