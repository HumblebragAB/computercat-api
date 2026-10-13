<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class GameEventRules
{
    public const IDENTITY = 'slug';

    public const FIELDS = ['slug', 'name', 'description', 'event_type', 'starts_at', 'ends_at', 'settings', 'is_active'];

    public const MAX_SETTINGS_BYTES = 16 * 1024;

    public static function settingsError(mixed $settings): ?string
    {
        if ($settings === null) {
            return null;
        }
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return 'settings must be a JSON object.';
        }
        if (strlen((string) json_encode($settings)) > self::MAX_SETTINGS_BYTES) {
            return 'settings can be at most 16 KB as JSON.';
        }

        return null;
    }

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'slug' => SlugRule::for($game, 'game_events', 'slug', 100, $existing),
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'in:leaderboard,challenge,seasonal'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'settings' => ['sometimes', function (string $a, mixed $v, \Closure $fail) {
                if ($e = self::settingsError($v)) {
                    $fail($e);
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
