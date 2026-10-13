<?php

namespace App\Mcp\Tools;

use App\Mcp\AuditLogger;
use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Listar spelen användaren når. Tar inget spel och ärver därför inte GameTool,
// men loggas på samma sätt (utan spel).
class ListGamesTool extends Tool
{
    protected string $name = 'list_games';

    protected string $description = 'Lists the games you can configure (also inactive ones), with slug, name, is_active and allowed_origins. Use the slug as "game" in the other tools.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = User::find($request->user()?->getKey());

        if (! $user || ! $user->isStaff()) {
            $this->audit($user, 'denied');

            return Response::error('Not allowed.');
        }

        // Fälten väljs uttryckligen: settings bär hemligheter och får aldrig följa med.
        $games = GameAccess::visible($user)->orderBy('slug')->get()
            ->map(fn (Game $game) => [
                'slug' => $game->slug,
                'name' => $game->name,
                'is_active' => (bool) $game->is_active,
                'allowed_origins' => $game->allowed_origins ?? [],
            ])->values()->all();

        $this->audit($user, 'ok');

        return Response::text(json_encode(['games' => $games], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function audit(?User $user, string $outcome): void
    {
        try {
            AuditLogger::record($user, null, $this->name(), [], $outcome);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
