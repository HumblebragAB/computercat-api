<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'settings',
        'is_active',
    ];

    // Bär hemligheter per spel (t.ex. revenuecat.webhook_secret). Ska aldrig
    // följa med när en modell serialiseras till ett svar.
    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function leaderboardTypes(): HasMany
    {
        return $this->hasMany(LeaderboardType::class);
    }

    public function achievementDefinitions(): HasMany
    {
        return $this->hasMany(AchievementDefinition::class);
    }

    public function gameSaves(): HasMany
    {
        return $this->hasMany(GameSave::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function dailyContentPools(): HasMany
    {
        return $this->hasMany(DailyContentPool::class);
    }

    public function playerStreaks(): HasMany
    {
        return $this->hasMany(PlayerStreak::class);
    }

    public function remoteConfigs(): HasMany
    {
        return $this->hasMany(RemoteConfig::class);
    }

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
