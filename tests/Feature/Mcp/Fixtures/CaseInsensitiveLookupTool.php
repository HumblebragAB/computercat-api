<?php

namespace Tests\Feature\Mcp\Fixtures;

use App\Mcp\Tools\UpsertLeaderboardTypeTool;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;

// Uppslag som beter sig som MySQL:s skiftlägesokänsliga kollation, så att
// skyddet i UpsertTool kan testas på SQLite.
class CaseInsensitiveLookupTool extends UpsertLeaderboardTypeTool
{
    protected function lookup(Game $game, string $identity): ?Model
    {
        return $game->leaderboardTypes()->whereRaw('lower(slug) = ?', [strtolower($identity)])->first();
    }

    public function callRun(Game $game, array $args, User $user): Response
    {
        return $this->run($game, $args, $user);
    }
}
