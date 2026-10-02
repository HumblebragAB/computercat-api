<?php

namespace App\Services\Glosis;

use RuntimeException;

/**
 * Ett anrop till ElevenLabs röst-API misslyckades. Meddelandet är på svenska och
 * visas i Filament; det innehåller aldrig API-nyckeln. $missingPermission är
 * behörigheten nyckeln saknar (t.ex. voices_read) när det var felet.
 */
final class VoiceLibraryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $missingPermission = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
