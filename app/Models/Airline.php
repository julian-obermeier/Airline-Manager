<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Airline extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'owner_user_id',
        'home_airport_id',
        'name',
        'slug',
        'icao_code',
        'iata_code',
        'callsign',
        'country_code',
        'base_currency',
        'business_model',
        'service_concept',
        'target_group',
        'starting_capital_minor',
        'status',
        'branding',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
        ];
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function homeAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'home_airport_id');
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(AirlineRoute::class, 'airline_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function reputationProfile(): HasOne
    {
        return $this->hasOne(AirlineReputationProfile::class);
    }

    public function marketingCampaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    public function routeMarketPositions(): HasMany
    {
        return $this->hasMany(RouteMarketPosition::class);
    }

    public function airportStations(): HasMany
    {
        return $this->hasMany(AirlineAirportStation::class);
    }

    public function slotReservations(): HasMany
    {
        return $this->hasMany(AirportSlotReservation::class);
    }

    public function crewMembers(): HasMany
    {
        return $this->hasMany(CrewMember::class);
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }
}
