<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMatchEvent extends Model
{
    public $timestamps = false;

    protected $table = 'match_events';

    protected $fillable = [
        'match_id',
        'seq',
        'type',
        'seat',
        'payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'seat' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
