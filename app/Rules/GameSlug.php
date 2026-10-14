<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Slug för ett nytt spel: blir GitHub-reponamn, Coolify-namn och DNS-etikett
// (<slug>.apps.humblebrag.se). 3–40 tecken, gemener, siffror och enkla
// bindestreck, börjar med bokstav, slutar inte med bindestreck.
class GameSlug implements ValidationRule
{
    public const PATTERN = '/^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){2,39}\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('The slug must be 3-40 characters: lowercase letters, digits and single hyphens, starting with a letter and not ending with a hyphen.');

            return;
        }

        if (self::isReserved($value)) {
            $fail('The slug is reserved.');
        }
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, (array) config('provisioning.reserved_slugs', []), true);
    }
}
