<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftProcurement extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id', 'airline_id', 'aircraft_type_id', 'market_offer_id', 'delivered_aircraft_id',
        'procurement_type', 'status', 'registration', 'total_price_minor', 'upfront_payment_minor',
        'monthly_payment_minor', 'lease_term_months', 'ordered_at', 'delivery_due_at', 'delivered_at',
        'next_payment_at', 'lease_ends_at', 'delivery_condition_percent', 'initial_flight_hours',
        'initial_flight_cycles', 'manufactured_on', 'currency', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'delivery_due_at' => 'datetime',
            'delivered_at' => 'datetime',
            'next_payment_at' => 'datetime',
            'lease_ends_at' => 'datetime',
            'delivery_condition_percent' => 'decimal:2',
            'initial_flight_hours' => 'decimal:2',
            'manufactured_on' => 'date',
            'metadata' => 'array',
        ];
    }

    public function world(): BelongsTo { return $this->belongsTo(World::class); }
    public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }
    public function type(): BelongsTo { return $this->belongsTo(AircraftType::class, 'aircraft_type_id'); }
    public function marketOffer(): BelongsTo { return $this->belongsTo(AircraftMarketOffer::class, 'market_offer_id'); }
    public function deliveredAircraft(): BelongsTo { return $this->belongsTo(Aircraft::class, 'delivered_aircraft_id'); }
}
