<?php

namespace App\Services\RevenueCat;

class RefundRule
{
    /**
     * RevenueCat har ingen REFUND-händelse. Återbetalningar kommer som
     * CANCELLATION med cancel_reason CUSTOMER_SUPPORT. Engångsköp har
     * expiration_at_ms = null och är makulerade oavsett skäl.
     */
    public static function isRevocation(string $type, array $event): bool
    {
        return $type === 'CANCELLATION'
            && (($event['cancel_reason'] ?? null) === 'CUSTOMER_SUPPORT' || ! isset($event['expiration_at_ms']));
    }
}
