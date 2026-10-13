<?php

namespace App\Models;

use App\Models\Concerns\HasStaffFlags;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasStaffFlags, Notifiable;

    // Panelen följer staff-flaggan (computercat:staff), inte e-postadressen:
    // adresser är overifierade och kan registreras av vem som helst.
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff();
    }

    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
        'apple_id',
        'google_id',
        'is_anonymous',
        'is_banned',
        'last_seen_at',
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
            'is_anonymous' => 'boolean',
            'is_banned' => 'boolean',
            'is_staff' => 'boolean',
            'is_platform_admin' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function leaderboardEntries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function gameSaves(): HasMany
    {
        return $this->hasMany(GameSave::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }
}
