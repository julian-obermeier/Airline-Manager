<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightCrewAssignment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'flight_id',
        'crew_member_id',
        'duty_role',
        'duty_start_at',
        'duty_end_at',
        'assigned_at',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'duty_start_at' => 'datetime',
            'duty_end_at' => 'datetime',
            'assigned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
