<?php

namespace App\Services\Glosis;

use RuntimeException;

/**
 * ElevenLabs gav inget användbart ljud: nätverksfel, felstatus eller ett svar
 * som inte är MP3. Meddelandet är internt och loggas, aldrig med API-nyckeln.
 */
final class TtsFailedException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
