<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Airport extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'icao_code',
        'iata_code',
        'name',
        'city',
        'country_code',
        'latitude',
        'longitude',
        'timezone',
        'elevation_ft',
        'passenger_capacity_yearly',
        'cargo_capacity_tonnes_yearly',
        'runways',
        'operational_restrictions',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'runways' => 'array',
            'operational_restrictions' => 'array',
            'metadata' => 'array',
        ];
    }
}
