<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class SlugRule
{
    // \z i stället för $ så att en avslutande radbrytning inte släpps igenom.
    public const PATTERN = '/^[a-z0-9][a-z0-9_.-]*\z/';

    /**
     * Formatet kontrolleras bara när värdet är nytt eller ändras, så att
     * befintliga poster med äldre slugs fortfarande går att redigera.
     * Unikheten gäller per spel och ignorerar posten själv vid redigering.
     */
    public static function for(Game $game, string $table, string $column, int $max, ?Model $existing): array
    {
        $unchanged = fn ($value) => $existing !== null && $existing->getAttribute($column) === $value;

        return [
            'required', 'string', "max:{$max}",
            function (string $attribute, mixed $value, \Closure $fail) use ($unchanged) {
                if (! $unchanged($value) && (! is_string($value) || ! preg_match(self::PATTERN, $value))) {
                    $fail('Use lowercase letters, digits, "-", "_" or "." and start with a letter or digit.');
                }
            },
            Rule::unique($table, $column)->where('game_id', $game->id)->ignore($existing?->getKey()),
        ];
    }
}
