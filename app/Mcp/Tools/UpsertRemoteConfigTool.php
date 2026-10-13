<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Support\ConfigRules\RemoteConfigRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpsertRemoteConfigTool extends UpsertTool
{
    protected string $name = 'upsert_remote_config';

    protected string $description = 'Create or update a remote config value for a game, identified by key. Set is_active false to disable it; there is no delete.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'key' => $schema->string()->description('Lowercase identifier, e.g. "max-hints"')->required(),
            'value' => $schema->string()->description('Always a string. int: "42", bool: "true"/"false", json: the JSON encoded as a string, e.g. "{\"a\":1}"')->required(),
            'value_type' => $schema->string()->enum(['string', 'int', 'bool', 'json'])->required(),
            'description' => $schema->string()->description('Internal note'),
            'is_active' => $schema->boolean(),
        ];
    }

    protected function rulesClass(): string
    {
        return RemoteConfigRules::class;
    }

    protected function relation(Game $game): HasMany
    {
        return $game->remoteConfigs();
    }

    // Värdet kontrolleras mot den value_type som skickas in.
    protected function rulesFor(Game $game, ?Model $existing, array $input): array
    {
        return RemoteConfigRules::rules($game, $existing, is_string($input['value_type'] ?? null) ? $input['value_type'] : null);
    }
}
