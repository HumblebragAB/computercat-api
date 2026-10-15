<?php

namespace App\Provisioning\Http;

use App\Provisioning\SafeMessage;

// Meddelandet visas för spelets medlemmar: bara tjänst, metod, sökväg (utan
// värd), status och redigerad body.
final class ExternalApiException extends \RuntimeException implements SafeMessage
{
    public function __construct(
        public readonly string $service,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        string $redactedBody,
        // Svaret bar en rate limit-signal (Retry-After eller X-RateLimit-Remaining: 0), t.ex. GitHubs
        // sekundära rate limit som svarar 403. Anroparen kan då behandla felet som tillfälligt.
        public readonly bool $rateLimited = false,
    ) {
        $what = $status === 0 ? 'failed to connect' : "returned {$status}";
        parent::__construct(Redactor::limit(Redactor::scrubSecrets("{$service} {$method} {$path} {$what}: {$redactedBody}")));
    }
}
