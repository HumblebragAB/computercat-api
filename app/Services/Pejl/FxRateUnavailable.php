<?php

namespace App\Services\Pejl;

// pending = försök igen senare (ej publicerad, 429, 5xx, onåbar); missing = ingen kurs inom lookback för en passerad dag.
final class FxRateUnavailable extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $pending)
    {
        parent::__construct($message);
    }

    public static function pending(string $message): self
    {
        return new self($message, true);
    }

    public static function missing(string $message): self
    {
        return new self($message, false);
    }
}
