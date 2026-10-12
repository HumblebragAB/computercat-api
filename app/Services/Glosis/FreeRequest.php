<?php

namespace App\Services\Glosis;

/** En godkänd gratisförfrågan: vem, och den signerade clientData (förfrågans innehåll). */
final readonly class FreeRequest
{
    /** @param array<string, mixed> $clientData */
    public function __construct(public GlosisCaller $caller, public array $clientData) {}
}
