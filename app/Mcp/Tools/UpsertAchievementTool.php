<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Support\ConfigRules\AchievementRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpsertAchievementTool extends UpsertTool
{
    protected string $name = 'upsert_achievement';

    protected string $description = 'Create or update an achievement definition for a game, identified by slug. Set is_active false to disable it; there is no delete.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'slug' => $schema->string()->description('Lowercase identifier, e.g. "first-win"')->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->description('Shown to players')->required(),
            'icon' => $schema->string()->description('Emoji or icon id, max 20 characters')->required(),
            'sort_order' => $schema->integer()->description('0-100000, lower is shown first'),
            'is_secret' => $schema->boolean()->description('Hidden from players until unlocked'),
            'is_active' => $schema->boolean(),
        ];
    }

    protected function rulesClass(): string
    {
        return AchievementRules::class;
    }

    protected function relation(Game $game): HasMany
    {
        return $game->achievementDefinitions();
    }
}
