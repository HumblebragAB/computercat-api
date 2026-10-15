<?php

namespace App\Releases;

// Versionen i package.json, som också blir CFBundleShortVersionString och taggen v<version>.
// Bara MAJOR.MINOR.PATCH med högst tre siffror per del och utan inledande nollor.
final class MarketingVersion implements \Stringable
{
    private const PATTERN = '/^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\z/';

    private function __construct(public readonly int $major, public readonly int $minor, public readonly int $patch) {}

    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function isHigherThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    // Förslaget i svaren ("till exempel 1.2.1").
    public function nextPatch(): self
    {
        return new self($this->major, $this->minor, $this->patch + 1);
    }

    /** @param iterable<mixed> $versions */
    public static function highest(iterable $versions): ?self
    {
        $best = null;
        foreach ($versions as $value) {
            $v = self::parse($value);
            if ($v !== null && ($best === null || $v->isHigherThan($best))) {
                $best = $v;
            }
        }

        return $best;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
