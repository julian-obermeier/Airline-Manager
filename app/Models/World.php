<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class World extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'slug',
        'name',
        'type',
        'status',
        'speed_multiplier',
        'simulated_at',
        'last_simulation_tick_at',
        'starts_at',
        'ends_at',
        'random_seed',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'speed_multiplier' => 'decimal:2',
            'simulated_at' => 'datetime',
            'last_simulation_tick_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'world_memberships')
            ->withPivot(['status', 'joined_at', 'last_active_at'])
            ->withTimestamps();
    }

    public function airlines(): HasMany
    {
        return $this->hasMany(Airline::class);
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }
}
