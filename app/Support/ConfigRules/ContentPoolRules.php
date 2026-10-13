<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class ContentPoolRules
{
    public const IDENTITY = 'pool_key';

    public const FIELDS = ['pool_key', 'content', 'starts_at', 'random_access', 'is_active'];

    public const MAX_ITEMS = 5000;

    public const MAX_BYTES = 512 * 1024;

    public static function contentError(mixed $content): ?string
    {
        if (! is_array($content) || ! array_is_list($content)) {
            return 'content must be an array (a JSON list), not a string or object.';
        }
        if (count($content) > self::MAX_ITEMS) {
            return 'content can hold at most '.self::MAX_ITEMS.' items.';
        }
        if (strlen((string) json_encode($content)) > self::MAX_BYTES) {
            return 'content can be at most 512 KB as JSON.';
        }

        return null;
    }

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'pool_key' => SlugRule::for($game, 'daily_content_pools', 'pool_key', 100, $existing),
            'content' => ['required', function (string $a, mixed $v, \Closure $fail) {
                if ($e = self::contentError($v)) {
                    $fail($e);
                }
            }],
            'starts_at' => ['nullable', 'date'],
            'random_access' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
