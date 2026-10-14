<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleaseSetupStep extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    protected $fillable = ['game_id', 'target', 'key', 'status', 'done_by', 'done_at', 'instruction'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
