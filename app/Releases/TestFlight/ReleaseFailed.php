<?php

namespace App\Releases\TestFlight;

use App\Provisioning\SafeMessage;

// Orsaken till att ett TestFlight-släpp fälls. Meddelandet skrivs av Computercat och får visas.
// $runFinished: körningen är redan slut eller avbruten, så fail() ska inte avbryta den igen.
final class ReleaseFailed extends \RuntimeException implements SafeMessage
{
    public function __construct(string $message, public readonly bool $runFinished = false)
    {
        parent::__construct($message);
    }
}
