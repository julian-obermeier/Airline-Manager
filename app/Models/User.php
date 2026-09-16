<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'country_code',
        'locale',
        'timezone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_settings' => 'array',
            'privacy_settings' => 'array',
        ];
    }

    public function worlds(): BelongsToMany
    {
        return $this->belongsToMany(World::class, 'world_memberships')
            ->withPivot(['status', 'joined_at', 'last_active_at'])
            ->withTimestamps();
    }

    public function ownedAirlines(): HasMany
    {
        return $this->hasMany(Airline::class, 'owner_user_id');
    }
}
