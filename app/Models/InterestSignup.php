<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterestSignup extends Model
{
    /** Språken man kan anmäla intresse för, med svensk etikett. */
    public const SPRAK = [
        'de' => 'Tyska',
        'es' => 'Spanska',
        'fr' => 'Franska',
        'other' => 'Annat',
    ];

    protected $fillable = ['game_id', 'email', 'language', 'token_hash', 'confirmation_sent_at', 'confirmed_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
