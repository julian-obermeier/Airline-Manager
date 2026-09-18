<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AirlineAirportStation extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'airport_id',
        'station_type',
        'status',
        'opened_at',
        'closed_at',
        'monthly_cost_minor',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }
}
