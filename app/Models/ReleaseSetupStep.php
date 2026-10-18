<?php

namespace App\Models;

use App\Provisioning\Http\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleaseSetupStep extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    protected $fillable = ['game_id', 'target', 'key', 'status', 'done_by', 'done_at', 'instruction', 'last_error', 'failed_attempts', 'retry_at'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'retry_at' => 'datetime', 'failed_attempts' => 'integer'];
    }

    protected static function booted(): void
    {
        // last_error visas i Filament och Slack: kända hemligheter bort och högst 4 KB.
        static::saving(function (ReleaseSetupStep $step) {
            if (is_string($step->last_error)) {
                $step->last_error = Redactor::limit(Redactor::scrubSecrets($step->last_error));
            }
        });
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
