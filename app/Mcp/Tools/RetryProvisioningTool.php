<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Models\User;
use App\Provisioning\Saga;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class RetryProvisioningTool extends GameTool
{
    protected string $name = 'retry_provisioning';

    protected string $description = 'Resume a failed provisioning or archive run from its first unfinished step. Only the game owner (or the creator, if the game was never created). Fix the cause first; get_provisioning_status shows the error.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    // Köar ett jobb; ingen transaktion runt dispatch.
    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! GameAccess::isOwner($user, $game)) {
            return Response::error('Only the game owner can retry provisioning.');
        }

        return $this->retry(ProvisioningRun::where('game_id', $game->id)->orderByDesc('id')->first());
    }

    protected function whenGameMissing(User $user, string $slug, array $args): ?Response
    {
        $run = ProvisioningRun::provision()->where('slug', $slug)->where('user_id', $user->id)->whereNull('game_id')->orderByDesc('id')->first();

        return $run === null ? null : $this->retry($run);
    }

    private function retry(?ProvisioningRun $run): Response
    {
        if ($run === null) {
            return Response::error('This game has no provisioning run.');
        }

        if ($run->status === ProvisioningRun::RELEASED) {
            return Response::error('The slug was released ('.$run->last_error.'). Start again with create_game and another slug.');
        }

        if ($run->status !== ProvisioningRun::FAILED || ! Saga::retry($run)) {
            return Response::error("Nothing to retry: the run is {$run->fresh()->status}.");
        }

        return $this->json([
            'run' => $run->uuid,
            'status' => ProvisioningRun::RUNNING,
            'resumes_at' => $run->steps()->where('status', '!=', ProvisioningStep::DONE)->value('step'),
        ]);
    }
}
