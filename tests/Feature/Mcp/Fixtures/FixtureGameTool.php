<?php

namespace Tests\Feature\Mcp\Fixtures;

use App\Mcp\Tools\GameTool;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

// Testverktyg som skriver till spelet och sedan beter sig enligt "mode".
class FixtureGameTool extends GameTool
{
    protected string $name = 'fixture_game_tool';

    protected string $description = 'Test fixture.';

    public function schema(JsonSchema $schema): array
    {
        return [...$this->gameSchema($schema), 'mode' => $schema->string()];
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $game->update(['name' => 'Ändrad']);

        return match ($args['mode'] ?? 'ok') {
            'invalid' => $this->validationFailed(['name' => ['The name is wrong.']]),
            'throw_validation' => throw ValidationException::withMessages(['key' => 'The key is wrong.']),
            'boom' => throw new \RuntimeException('internal detail '.$game->id),
            default => $this->json(['game' => $game->slug, 'name' => $game->name, 'user' => $user->id]),
        };
    }
}
