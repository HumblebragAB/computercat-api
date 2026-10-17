<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMatchPlayer extends Model
{
    protected $table = 'match_players';

    // hidden_state och rematch_requested sätts bara uttryckligen av MatchService.
    protected $fillable = [
        'match_id',
        'user_id',
        'seat',
        'name',
        'last_seen_at',
    ];

    protected $hidden = ['hidden_state'];

    protected function casts(): array
    {
        return [
            'seat' => 'integer',
            'hidden_state' => 'encrypted:array',
            'last_seen_at' => 'datetime',
            'rematch_requested' => 'boolean',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
