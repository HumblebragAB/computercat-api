<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Support\ConfigRules\LeaderboardTypeRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpsertLeaderboardTypeTool extends UpsertTool
{
    protected string $name = 'upsert_leaderboard_type';

    protected string $description = 'Create or update a leaderboard type for a game, identified by slug. Set is_active false to disable it; there is no delete.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'slug' => $schema->string()->description('Lowercase identifier, e.g. "daily-time"')->required(),
            'name' => $schema->string()->required(),
            'sort_direction' => $schema->string()->enum(['asc', 'desc'])->description('asc = lower is better (time), desc = higher is better (score)')->required(),
            'score_label' => $schema->string()->description('Shown to players, e.g. "Time"')->required(),
            'period' => $schema->string()->enum(['daily', 'weekly', 'monthly', 'all_time'])->required(),
            'max_entries_per_period' => $schema->integer()->description('1-10000, default 100'),
            'is_active' => $schema->boolean(),
        ];
    }

    protected function rulesClass(): string
    {
        return LeaderboardTypeRules::class;
    }

    protected function relation(Game $game): HasMany
    {
        return $game->leaderboardTypes();
    }
}
