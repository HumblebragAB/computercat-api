<?php

namespace App\Services\Glosis;

use RuntimeException;

/**
 * Claude kunde inte ge en användbar glosläxa: API-fel, vägran eller svar som
 * inte klarar valideringen. Meddelandet är internt och loggas; användaren får
 * ett eget, barnvänligt meddelande.
 *
 * Kom ett svar från Claude (vägran, trasig JSON) är anropet ändå debiterat, och
 * då bär undantaget svarets token så att kostnaden kan bokföras. Vid nätverks-
 * eller API-fel är token null.
 */
final class ScanFailedException extends RuntimeException
{
    public ?string $model = null;

    public ?int $inputTokens = null;

    public ?int $outputTokens = null;

    public function withUsage(string $model, int $inputTokens, int $outputTokens): self
    {
        $this->model = $model;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;

        return $this;
    }

    public function wasBilled(): bool
    {
        return $this->inputTokens !== null && $this->outputTokens !== null;
    }
}
