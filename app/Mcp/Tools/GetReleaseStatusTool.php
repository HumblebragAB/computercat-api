<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use App\Releases\ReleaseStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class GetReleaseStatusTool extends GameTool
{
    protected string $name = 'get_release_status';

    protected string $description = 'Show where a game is in the release flow: its production targets (web, app_store, play_store), the latest commit with a successful Demo build, the latest release per stage, open one-time setup steps, and "next" (what to do now, in Swedish). Reads Computercat\'s own records; receipts of Demo builds can be up to five minutes old. Any member.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        return $this->json(ReleaseStatus::forGame($game));
    }
}
