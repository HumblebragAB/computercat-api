<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Support\ConfigRules\ContentPoolRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class UpsertContentPoolTool extends UpsertTool
{
    protected string $name = 'upsert_content_pool';

    protected string $description = 'Create or update a daily content pool for a game, identified by pool_key. content replaces the whole list. Set is_active false to disable it; there is no delete.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'pool_key' => $schema->string()->description('Lowercase identifier, e.g. "words"')->required(),
            'content' => $schema->array()->description('JSON list, max 5000 items / 512 KB. Replaces the previous content.')->required(),
            'starts_at' => $schema->string()->description('Date (YYYY-MM-DD) from which the pool is used; omit or null for always')->nullable(),
            'random_access' => $schema->boolean()->description('If true, every player can read the whole pool, not just the item of the day'),
            'is_active' => $schema->boolean(),
        ];
    }

    protected function rulesClass(): string
    {
        return ContentPoolRules::class;
    }

    protected function relation(Game $game): HasMany
    {
        return $game->dailyContentPools();
    }

    // Kolumnen är en date och API:t jämför den som Y-m-d-sträng. Panelen
    // sparar Y-m-d, så MCP gör detsamma (efter omräkning till appens tidszon).
    protected function prepare(array $validated): array
    {
        if (isset($validated['starts_at'])) {
            $validated['starts_at'] = Carbon::parse($validated['starts_at'])->setTimezone(config('app.timezone'))->toDateString();
        }

        return $validated;
    }
}
