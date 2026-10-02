<?php

namespace App\Services\Glosis;

/**
 * Ett verifierat butiksköp, oavsett butik.
 *
 * Apple: fälten följer JWSTransactionDecodedPayload
 * https://developer.apple.com/documentation/appstoreserverapi/jwstransactiondecodedpayload
 *
 * Google Play: se GooglePurchaseVerifier. originalTransactionId är orderId
 * (GPA.…), samma id som RevenueCat skickar som transaction_id och
 * original_transaction_id, så spärrlistan och gränserna fungerar likadant.
 *
 * environment är "Production" eller "Sandbox" (TestFlight, Googles
 * testköp), "Admin" för förhandslyssning i Filament.
 */
final readonly class VerifiedTransaction
{
    public function __construct(
        public string $originalTransactionId,
        public string $transactionId,
        public string $productId,
        public string $environment,
        public ?int $revocationDate,
        /** Butiken säger själv att köpet inte ger något (Google: makulerat, väntande, förbrukat eller återbetalt). */
        public bool $storeRevoked = false,
        /** Köptidpunkt i millisekunder, om butiken anger den. Avgör aldrig giltigheten (det gör produkt-id:t). */
        public ?int $purchaseDate = null,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revocationDate !== null || $this->storeRevoked;
    }
}
