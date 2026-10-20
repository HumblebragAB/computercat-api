<?php

namespace App\Services\Pejl;

use Carbon\CarbonImmutable;

// Riksbankens dagskurs: SEK per en enhet av $currency, publicerad för $date.
final class FxQuote
{
    public function __construct(
        public readonly string $currency,
        public readonly float $rate,
        public readonly CarbonImmutable $date,
    ) {}
}
