<?php

namespace App\Mcp\Tools;

use App\Jobs\RunAppStoreSetup;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\AppStore\BundleId;
use App\Releases\ReleaseCatalog;
use App\Releases\ReleaseStatus;
use App\Releases\SetupSteps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

class SetReleaseTargetsTool extends GameTool
{
    protected string $name = 'set_release_targets';

    protected string $description = 'Set which production targets a game has: any of web, app_store, play_store, as a comma-separated string ("web,app_store"; empty string = none). Creates the one-time setup steps for each target. Only a platform admin. Removing a target that has a pending release is refused. Adding app_store gives the game its bundle id (se.computercat.<slug without hyphens>) and starts Computercat\'s App Store setup steps.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            // En sträng, inte en lista: revisionsloggen visar bara "[list: N items]" för listor (R6).
            'targets' => $schema->string()->description('Comma-separated targets: web, app_store, play_store. Empty string clears all targets.')->required(),
        ];
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! $user->isPlatformAdmin()) {
            return Response::error('Only a platform admin can set release targets.');
        }

        $validator = Validator::make($args, ['targets' => ['present', 'string', 'max:100']]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        // Verktyget körs i en transaktion: spelets rad låses, samma lås som ReleaseService::promote tar,
        // så att målen inte ändras mitt under en befordran.
        $game = Game::query()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

        $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', $validator->validated()['targets'])), fn (string $t) => $t !== '')));
        $unknown = array_values(array_diff($targets, ReleaseCatalog::TARGETS));
        if ($unknown !== []) {
            return $this->validationFailed(['targets' => 'Unknown target(s): '.implode(', ', array_map(fn ($t) => mb_substr($t, 0, 30), $unknown)).'. Allowed: '.implode(', ', ReleaseCatalog::TARGETS).'.']);
        }

        $removed = array_diff($game->releaseTargets(), $targets);
        foreach ($removed as $target) {
            if (Release::active()->where('game_id', $game->id)->where('target', $target)->exists()) {
                return Response::error("Cannot remove {$target}: the game has a pending release for it. Wait for it to finish or cancel it first.");
            }
        }

        if (in_array('app_store', $targets, true) && $game->bundle_id === null) {
            $bundle = BundleId::forSlug($game->slug);
            // Specen: krockar två slugs vägrar verktyget, och Erik väljer ett annat id. Vilket spel som
            // har id:t sägs inte (spelet kan tillhöra någon annan).
            if (Game::where('bundle_id', $bundle)->whereKeyNot($game->getKey())->exists()) {
                return self::bundleIdTaken($bundle);
            }
            $game->forceFill(['bundle_id' => $bundle]);
        }

        try {
            $game->forceFill(['release_targets' => $targets])->save();
        } catch (UniqueConstraintViolationException) {
            // Ett annat anrop hann ta samma bundle-id mellan kontrollen och sparandet (unikt index).
            return self::bundleIdTaken((string) $game->bundle_id);
        }
        SetupSteps::sync($game);

        if (in_array('app_store', $targets, true)) {
            // Verktyget körs i en transaktion; jobbet (HTTP mot Apple) startar först efter commit.
            RunAppStoreSetup::dispatch($game->id)->afterCommit();
        }

        $game = $game->fresh();
        $status = ReleaseStatus::forGame($game);

        return $this->json([
            'saved' => true,
            'release_targets' => $game->releaseTargets(),
            'open_setup_steps' => ReleaseSetupStep::where('game_id', $game->id)->whereIn('target', $targets)
                ->where('status', ReleaseSetupStep::OPEN)->orderBy('id')->get(['target', 'key', 'instruction'])->toArray(),
            'next' => $status['next'],
        ]);
    }

    private static function bundleIdTaken(string $bundle): Response
    {
        return Response::error("Bundle id {$bundle} is already used by another game. Ask Erik to choose another bundle id for this game.");
    }
}
