<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameMatch extends Model
{
    use HasUlids;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'matches';

    // status och version sätts bara uttryckligen av MatchService, aldrig via fill/create.
    protected $fillable = [
        'game_id',
        'ruleset',
        'options',
        'join_token_hash',
        'state',
        'last_action_at',
        'expires_at',
        'rematch_of',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'state' => 'array',
            'version' => 'integer',
            'expires_at' => 'datetime',
            'last_action_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(GameMatchPlayer::class, 'match_id')->orderBy('seat');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameMatchEvent::class, 'match_id')->orderBy('seq');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_WAITING, self::STATUS_ACTIVE]);
    }
}
