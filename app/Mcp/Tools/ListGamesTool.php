<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

// Listar spelen användaren når. Tar inget spel; loggas via PlatformTool.
class ListGamesTool extends PlatformTool
{
    protected string $name = 'list_games';

    protected string $description = 'Lists the games you can configure (also inactive ones), with slug, name, is_active and allowed_origins. Use the slug as "game" in the other tools.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $args, User $user): Response
    {
        // Fälten väljs uttryckligen: settings bär hemligheter och får aldrig följa med.
        $games = GameAccess::visible($user)->orderBy('slug')->get()
            ->map(fn (Game $game) => [
                'slug' => $game->slug,
                'name' => $game->name,
                'is_active' => (bool) $game->is_active,
                'allowed_origins' => $game->allowed_origins ?? [],
            ])->values()->all();

        return $this->json(['games' => $games]);
    }
}
