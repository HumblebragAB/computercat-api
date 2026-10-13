<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyContentPool extends Model
{
    protected $fillable = [
        'game_id',
        'pool_key',
        'content',
        'starts_at',
        'is_active',
        'random_access',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'starts_at' => 'date',
            'is_active' => 'boolean',
            'random_access' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Pools for the game that are active and have started on the given date.
     */
    public function scopeAvailable(Builder $query, Game $game, string $poolKey, string $date): Builder
    {
        return $query->where('game_id', $game->id)
            ->where('pool_key', $poolKey)
            ->where('is_active', true)
            ->where(function ($query) use ($date) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $date);
            });
    }

    /**
     * Up to $count distinct items in random order. Content that is not a list yields none.
     */
    public function randomItems(int $count): array
    {
        $content = $this->content;

        if (! is_array($content) || ! array_is_list($content)) {
            return [];
        }

        return collect($content)->shuffle()->take($count)->values()->all();
    }

    /**
     * Get the content item for a given date using deterministic selection.
     */
    public function contentForDate(string $date): mixed
    {
        $content = $this->content;

        if (empty($content)) {
            return null;
        }

        $hash = crc32($date . ':' . $this->id);
        $index = abs($hash) % count($content);

        return $content[$index];
    }
}
