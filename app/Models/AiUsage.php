<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ett betalt AI-anrop. Se migrationen create_ai_usage_table och
 * App\Services\Glosis\AiBudget.
 */
class AiUsage extends Model
{
    public const FEATURE_SCAN = 'scan';

    public const FEATURE_TTS = 'tts';

    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected $fillable = [
        'game_id',
        'feature',
        'environment',
        'units',
        'input_tokens',
        'output_tokens',
        'characters',
        'est_cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'characters' => 'integer',
            'est_cost_usd' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
