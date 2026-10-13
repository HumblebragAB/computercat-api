<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Support\ConfigRules\GameEventRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class UpsertGameEventTool extends UpsertTool
{
    protected string $name = 'upsert_game_event';

    protected string $description = 'Create or update a game event, identified by slug. settings replaces the previous settings (omit to keep them, null to clear). Set is_active false to disable it; there is no delete.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'slug' => $schema->string()->description('Lowercase identifier, e.g. "christmas-2026"')->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'event_type' => $schema->string()->enum(['leaderboard', 'challenge', 'seasonal'])->required(),
            'starts_at' => $schema->string()->description('ISO 8601 with offset, e.g. "2026-12-01T00:00:00+01:00"')->required(),
            'ends_at' => $schema->string()->description('ISO 8601 with offset, after starts_at')->required(),
            'settings' => $schema->object()->description('JSON object, max 16 KB. Replaces the previous settings; omit to keep them, null to clear.')->nullable(),
            'is_active' => $schema->boolean(),
        ];
    }

    protected function rulesClass(): string
    {
        return GameEventRules::class;
    }

    protected function relation(Game $game): HasMany
    {
        return $game->gameEvents();
    }

    // Eloquent sparar en ISO-tid med offset utan att räkna om den, så tiden
    // görs om till appens tidszon (UTC) före sparning.
    protected function prepare(array $validated): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = Carbon::parse($validated[$field])->setTimezone(config('app.timezone'));
            }
        }

        return $validated;
    }
}
