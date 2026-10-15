<?php

namespace App\Releases\TestFlight;

use App\Releases\MarketingVersion;

final class PreflightResult
{
    public function __construct(public readonly MarketingVersion $version, public readonly string $buildGuide) {}
}
