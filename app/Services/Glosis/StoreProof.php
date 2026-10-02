<?php

namespace App\Services\Glosis;

/**
 * Köpbeviset som appen skickar, kontrollerat till formen (GuldGate::parse).
 *
 *  iOS:     {"platform": "ios", "jws": "<StoreKit 2 JWSTransaction>"}
 *  Android: {"platform": "android", "productId": "glosis_guld_2026_27", "purchaseToken": "..."}
 */
final readonly class StoreProof
{
    public const IOS = 'ios';

    public const ANDROID = 'android';

    private function __construct(
        public string $platform,
        public ?string $jws = null,
        public ?string $productId = null,
        public ?string $purchaseToken = null,
    ) {}

    public static function ios(string $jws): self
    {
        return new self(self::IOS, jws: $jws);
    }

    public static function android(string $productId, string $purchaseToken): self
    {
        return new self(self::ANDROID, productId: $productId, purchaseToken: $purchaseToken);
    }
}
