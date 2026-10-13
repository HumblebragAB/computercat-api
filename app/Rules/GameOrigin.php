<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// En origin per spel: https, host och ev. port. Inga jokertecken; de statiska
// posterna i config/cors.php berörs inte av den här regeln.
class GameOrigin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url(rtrim($value, '/')) : false;

        $ok = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/i', $parts['host'])
            && array_intersect_key($parts, array_flip(['path', 'query', 'fragment', 'user', 'pass'])) === [];

        if (! $ok) {
            $fail('Each origin must look like https://host or https://host:port, without path or wildcards.');
        }
    }
}
