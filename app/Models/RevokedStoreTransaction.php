<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ett återbetalt butiksköp som inte längre ger något. Se migrationen
 * create_revoked_store_transactions_table.
 */
class RevokedStoreTransaction extends Model
{
    protected $fillable = [
        'game_id',
        'store',
        'original_transaction_id',
        'transaction_id',
        'reason',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public static function isRevoked(Game $game, string $originalTransactionId): bool
    {
        return self::where('game_id', $game->id)->where('original_transaction_id', $originalTransactionId)->exists();
    }
}
