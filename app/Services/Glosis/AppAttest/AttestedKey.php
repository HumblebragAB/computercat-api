<?php

namespace App\Services\Glosis\AppAttest;

final readonly class AttestedKey
{
    public function __construct(
        /** SHA256 av publika nyckeln (32 byte), samma som appens keyId. */
        public string $keyId,
        public string $publicKeyPem,
        /** production eller development (aaguid). */
        public string $environment,
        public ?string $bundleVersion,
        public ?int $validationCategory,
    ) {}
}
