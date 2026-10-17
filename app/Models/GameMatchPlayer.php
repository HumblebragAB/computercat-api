<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMatchPlayer extends Model
{
    protected $table = 'match_players';

    protected $fillable = [
        'match_id',
        'user_id',
        'seat',
        'name',
        'hidden_state',
        'last_seen_at',
        'rematch_requested',
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
