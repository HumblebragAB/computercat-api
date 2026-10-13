<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class AchievementRules
{
    public const IDENTITY = 'slug';

    public const FIELDS = ['slug', 'name', 'description', 'icon', 'sort_order', 'is_secret', 'is_active'];

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'slug' => SlugRule::for($game, 'achievement_definitions', 'slug', 50, $existing),
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_secret' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
