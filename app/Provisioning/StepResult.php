<?php

namespace App\Provisioning;

final class StepResult
{
    public const DONE = 'done';

    public const WAIT = 'wait';

    public const RELEASE = 'release';

    private function __construct(
        public readonly string $outcome,
        public readonly array $detail = [],
        public readonly int $seconds = 0,
        public readonly ?string $reason = null,
    ) {}

    public static function done(array $detail = []): self
    {
        return new self(self::DONE, $detail);
    }

    // Steget är inte klart än (README inte skapad, Coolify-appen inte borta): jobbet släpps och körs igen.
    public static function wait(int $seconds, array $detail = []): self
    {
        return new self(self::WAIT, $detail, $seconds);
    }

    // Slugen kan inte användas (t.ex. upptagen på GitHub av någon annan): runnen släpper den.
    public static function releaseSlug(string $reason): self
    {
        return new self(self::RELEASE, reason: $reason);
    }
}
