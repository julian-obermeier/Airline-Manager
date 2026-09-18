<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrewMember extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'world_id',
        'airline_id',
        'home_airport_id',
        'current_airport_id',
        'employee_number',
        'first_name',
        'last_name',
        'role',
        'status',
        'monthly_salary_minor',
        'currency',
        'hired_at',
        'terminated_at',
        'max_duty_minutes_day',
        'min_rest_minutes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'hired_at' => 'datetime',
            'terminated_at' => 'datetime',
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

    public function homeAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'home_airport_id');
    }

    public function currentAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'current_airport_id');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(CrewQualification::class);
    }

    public function flightAssignments(): HasMany
    {
        return $this->hasMany(FlightCrewAssignment::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
