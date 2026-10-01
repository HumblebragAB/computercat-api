<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $fillable = [
        'user_id',
        'game_id',
        'product_id',
        'store',
        'transaction_id',
        'receipt_data',
        'status',
        'purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
        ];
    }

    /**
     * RevenueCat-händelser som skapar eller uppdaterar ett köps receipt_data.
     */
    public const WEBHOOK_EVENT_TYPES = [
        'INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'RENEWAL', 'UNCANCELLATION',
        'CANCELLATION', 'EXPIRATION',
    ];

    /**
     * Skapades raden av RevenueCat-webhooken?
     *
     * Webhooken sparar hela händelsen som JSON i receipt_data, med samma
     * transaction_id som raden. Rader från det borttagna /purchases/verify
     * har tom, klientstyrd eller saknad receipt_data och räknas inte.
     */
    public function isFromWebhook(): bool
    {
        $event = json_decode((string) $this->receipt_data, true);

        return is_array($event)
            && in_array($event['type'] ?? null, self::WEBHOOK_EVENT_TYPES, true)
            && ($event['transaction_id'] ?? null) === $this->transaction_id;
    }

    /**
     * Ger köpet åtkomst? 'verified' gör det. 'pending' bara när raden kommer
     * från webhooken (prenumeration som sagts upp men inte löpt ut).
     * 'refunded' och 'failed' gör det aldrig.
     */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            'verified' => true,
            'pending' => $this->isFromWebhook(),
            default => false,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
