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
    ) {
        $what = $status === 0 ? 'failed to connect' : "returned {$status}";
        parent::__construct(Redactor::limit(Redactor::scrubSecrets("{$service} {$method} {$path} {$what}: {$redactedBody}")));
    }
}
