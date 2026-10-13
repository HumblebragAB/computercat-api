<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class LeaderboardTypeRules
{
    public const IDENTITY = 'slug';

    public const FIELDS = ['slug', 'name', 'sort_direction', 'score_label', 'period', 'max_entries_per_period', 'is_active'];

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'slug' => SlugRule::for($game, 'leaderboard_types', 'slug', 50, $existing),
            'name' => ['required', 'string', 'max:100'],
            'sort_direction' => ['required', 'in:asc,desc'],
            'score_label' => ['required', 'string', 'max:50'],
            'period' => ['required', 'in:daily,weekly,monthly,all_time'],
            'max_entries_per_period' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
