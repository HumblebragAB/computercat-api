<?php

namespace App\Mcp\Tools;

use App\Matches\RulesetRegistry;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class GetGameTool extends GameTool
{
    protected string $name = 'get_game';

    protected string $description = 'Show a game\'s configuration: leaderboard types, achievements, content pools (item counts only), remote config, events and allowed origins. Never includes game settings or content pool items.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    // Varje lista byggs med uttryckliga fält. Aldrig toArray() på en modell:
    // settings bär hemligheter och poolinnehåll kan vara svarsfacit.
    protected function run(Game $game, array $args, User $user): Response
    {
        return $this->json([
            'slug' => $game->slug,
            'name' => $game->name,
            'description' => $game->description,
            'is_active' => (bool) $game->is_active,
            'allowed_origins' => $game->allowed_origins ?? [],
            'match_rulesets' => RulesetRegistry::forGame($game),
            'base_url' => rtrim((string) config('app.url'), '/').'/api/v1',
            'leaderboard_types' => $game->leaderboardTypes()->orderBy('slug')->get()->map(fn ($r) => [
                'slug' => $r->slug,
                'name' => $r->name,
                'sort_direction' => $r->sort_direction,
                'score_label' => $r->score_label,
                'period' => $r->period,
                'max_entries_per_period' => $r->max_entries_per_period,
                'is_active' => $r->is_active,
            ])->all(),
            'achievements' => $game->achievementDefinitions()->orderBy('sort_order')->orderBy('slug')->get()->map(fn ($r) => [
                'slug' => $r->slug,
                'name' => $r->name,
                'description' => $r->description,
                'icon' => $r->icon,
                'sort_order' => $r->sort_order,
                'is_secret' => $r->is_secret,
                'is_active' => $r->is_active,
            ])->all(),
            // Bara antalet poster; innehållet utelämnas medvetet.
            'content_pools' => $game->dailyContentPools()->orderBy('pool_key')->get()->map(fn ($r) => [
                'pool_key' => $r->pool_key,
                'items' => is_array($r->content) ? count($r->content) : 0,
                'starts_at' => $r->starts_at?->toDateString(),
                'random_access' => $r->random_access,
                'is_active' => $r->is_active,
            ])->all(),
            'remote_configs' => $game->remoteConfigs()->orderBy('key')->get()->map(fn ($r) => [
                'key' => $r->key,
                'value' => $r->value,
                'value_type' => $r->value_type,
                'description' => $r->description,
                'is_active' => $r->is_active,
            ])->all(),
            // Eventets egen settings (inte spelets) ingår.
            'events' => $game->gameEvents()->orderBy('slug')->get()->map(fn ($r) => [
                'slug' => $r->slug,
                'name' => $r->name,
                'description' => $r->description,
                'event_type' => $r->event_type,
                'starts_at' => $r->starts_at?->toIso8601String(),
                'ends_at' => $r->ends_at?->toIso8601String(),
                'settings' => $r->settings,
                'is_active' => $r->is_active,
            ])->all(),
        ]);
    }
}
