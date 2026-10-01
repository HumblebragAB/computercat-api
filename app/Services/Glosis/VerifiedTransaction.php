<?php

namespace App\Services\Glosis;

/**
 * Det vi läser ur en verifierad JWSTransaction. Fältnamnen följer Apples
 * JWSTransactionDecodedPayload:
 * https://developer.apple.com/documentation/appstoreserverapi/jwstransactiondecodedpayload
 */
final readonly class VerifiedTransaction
{
    public function __construct(
        public string $originalTransactionId,
        public string $transactionId,
        public string $productId,
        public string $environment,
        public ?int $revocationDate,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revocationDate !== null;
    }
}
