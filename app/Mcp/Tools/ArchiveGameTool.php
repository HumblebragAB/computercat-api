<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Provisioning\Saga;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Response;

// Startar arkiveringssagan: Coolify-appen tas bort på sitt sparade uuid,
// SiteOps-sajten avaktiveras, repot arkiveras (raderas aldrig) och spelet
// stängs av. Bara ägaren, bara Demo-spel som provisionerats lyckat.
class ArchiveGameTool extends GameTool
{
    protected string $name = 'archive_game';

    protected string $description = 'Archive a Demo game that was created with create_game: removes its Demo site in Coolify, deactivates monitoring, archives (never deletes) the GitHub repo and deactivates the game. Only the game owner, only Demo games (not listed). Follow up with get_provisioning_status.';

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
        if (! GameAccess::isOwner($user, $game)) {
            return Response::error('Only the game owner can archive it.');
        }

        $provision = ProvisioningRun::provision()->where('game_id', $game->id)->where('status', ProvisioningRun::SUCCEEDED)->orderByDesc('id')->first();
        if ($provision === null) {
            return Response::error('Only games with a successful provisioning run can be archived.');
        }

        if ($game->is_listed) {
            return Response::error('The game is listed (Live). Archiving is only allowed in Demo; ask Erik.');
        }

        // Kontroll och skapande under ett lås per spel, så att två samtidiga
        // anrop inte startar två arkiveringssagor mot samma uuid.
        try {
            $archive = Cache::lock("provisioning:archive-game:{$game->id}", 10)->block(5, fn () => $this->createArchiveRun($game, $provision, $user));
        } catch (LockTimeoutException) {
            return Response::error('Another archive_game call for this game is being processed. Nothing was started.');
        }
        if ($archive instanceof Response) {
            return $archive;
        }

        try {
            Saga::start($archive);
        } catch (\Throwable $e) {
            // Annars spärrar en running-run utan jobb all vidare arkivering.
            $archive->update(['status' => ProvisioningRun::FAILED, 'last_error' => 'saga: could not be started. Use retry_provisioning.']);

            throw $e;
        }

        return $this->json(['archive_run' => $archive->uuid, 'status' => ProvisioningRun::RUNNING]);
    }

    private function createArchiveRun(Game $game, ProvisioningRun $provision, User $user): ProvisioningRun|Response
    {
        $existing = ProvisioningRun::where('kind', ProvisioningRun::KIND_ARCHIVE)->where('game_id', $game->id)
            ->whereIn('status', [ProvisioningRun::RUNNING, ProvisioningRun::FAILED])->first();
        if ($existing !== null) {
            return Response::error("An archive run already exists (status {$existing->status}). Use get_provisioning_status, or retry_provisioning if it failed.");
        }

        return ProvisioningRun::create([
            'slug' => $game->slug,
            'slug_lock' => null,
            'name' => $game->name,
            'user_id' => $user->id,
            'game_id' => $game->id,
            'kind' => ProvisioningRun::KIND_ARCHIVE,
            'status' => ProvisioningRun::RUNNING,
            'idea' => null,
            // Bara det provisioneringen sparade får röras.
            'github_repo' => $provision->github_repo,
            'coolify_app_uuid' => $provision->coolify_app_uuid,
            'siteops_site_id' => $provision->siteops_site_id,
            'demo_url' => $provision->demo_url,
        ]);
    }
}
