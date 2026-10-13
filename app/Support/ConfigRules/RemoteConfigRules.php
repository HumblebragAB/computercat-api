<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class RemoteConfigRules
{
    public const IDENTITY = 'key';

    public const FIELDS = ['key', 'value', 'value_type', 'description', 'is_active'];

    // Speglar RemoteConfig::typedValue(): det som sparas måste gå att läsa tillbaka.
    public static function valueError(?string $type, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'value must be a string; encode JSON values as a string.';
        }

        return match ($type) {
            'int' => preg_match('/^-?\d+$/', $value) ? null : 'value must be an integer for value_type int.',
            'bool' => in_array(strtolower($value), ['true', 'false', '1', '0'], true) ? null : 'value must be true/false/1/0 for value_type bool.',
            'json' => json_validate($value) ? null : 'value must be valid JSON for value_type json.',
            'string' => strlen($value) <= 65535 ? null : 'value is too long.',
            default => null,
        };
    }

    public static function rules(Game $game, ?Model $existing, ?string $type): array
    {
        return [
            'key' => SlugRule::for($game, 'remote_configs', 'key', 100, $existing),
            'value_type' => ['required', 'in:string,int,bool,json'],
            'value' => ['required', function (string $a, mixed $v, \Closure $fail) use ($type) {
                if ($e = self::valueError($type, $v)) {
                    $fail($e);
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
