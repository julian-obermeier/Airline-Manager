<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AirlineReputationProfile extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'awareness_score',
        'reputation_score',
        'satisfaction_score',
        'service_quality_score',
        'brand_value_minor',
        'completed_flights',
        'cancelled_flights',
        'last_evaluated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'awareness_score' => 'decimal:2',
            'reputation_score' => 'decimal:2',
            'satisfaction_score' => 'decimal:2',
            'service_quality_score' => 'decimal:2',
            'last_evaluated_at' => 'datetime',
            'metadata' => 'array',
        ];
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
