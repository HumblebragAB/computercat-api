<?php

namespace App\Matches;

use RuntimeException;

/**
 * Ett kodat fel från matchgrunden. Controllern översätter det till
 * HTTP $status med body {message, code, ...extra}.
 */
class MatchError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  extra publika fält i felsvaret (t.ex. version vid stale)
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $extra = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public static function inviteExpired(): self
    {
        return new self('invite_expired', 410, [], 'Inbjudan är ogiltig eller har gått ut.');
    }

    public static function ownMatch(): self
    {
        return new self('own_match', 409, [], 'Du kan inte gå med i din egen match.');
    }

    public static function tooManyActive(): self
    {
        return new self('too_many_active', 429, [], 'Du har för många pågående matcher i spelet.');
    }

    public static function wrongPhase(): self
    {
        return new self('wrong_phase', 422, [], 'Det går inte i matchens nuvarande läge.');
    }

    public static function stale(int $version): self
    {
        return new self('stale', 409, ['version' => $version], 'Matchen har ändrats. Hämta den igen.');
    }
}
