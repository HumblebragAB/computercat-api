<?php

namespace App\Models;

use App\Support\GameCorsOrigins;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'settings',
        'allowed_origins',
        'is_active',
    ];

    // Bär hemligheter per spel (t.ex. revenuecat.webhook_secret). Ska aldrig
    // följa med när en modell serialiseras till ett svar.
    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'allowed_origins' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Normaliseras vid sparning så att värdet matchar webbläsarens Origin-header.
        static::saving(function (Game $game) {
            if (is_array($game->allowed_origins)) {
                $game->allowed_origins = array_values(array_unique(array_map(
                    [GameCorsOrigins::class, 'normalize'], $game->allowed_origins
                )));
            }
        });

        static::saved(fn () => GameCorsOrigins::forget());
        static::deleted(fn () => GameCorsOrigins::forget());
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

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
