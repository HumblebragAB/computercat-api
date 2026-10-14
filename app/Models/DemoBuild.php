<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Kvitto per lyckat Demo-bygge (spärr 1). Skrivs bara av DemoBuildRecorder.
class DemoBuild extends Model
{
    protected $fillable = ['game_id', 'commit_sha', 'deployment_uuid', 'deployed_at'];

    protected function casts(): array
    {
        return ['deployed_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public static function latestFor(Game $game): ?DemoBuild
    {
        return static::where('game_id', $game->id)->orderByDesc('deployed_at')->orderByDesc('id')->first();
    }
}
