<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AircraftType extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'manufacturer',
        'model',
        'variant',
        'icao_type_code',
        'typical_seats',
        'max_seats',
        'range_km',
        'cruise_speed_kmh',
        'minimum_runway_m',
        'max_payload_kg',
        'fuel_capacity_l',
        'reference_purchase_price_minor',
        'reference_currency',
        'production_status',
        'technical_data',
    ];

    protected function casts(): array
    {
        return [
            'technical_data' => 'array',
        ];
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }
}
