<?php

namespace App\Mcp\Tools;

use App\Jobs\RunAppStoreSetup;
use App\Models\Game;
use App\Models\Release;
use App\Models\User;
use App\Releases\AppStore\BundleId;
use App\Releases\ReleaseCatalog;
use App\Releases\ReleaseStatus;
use App\Releases\SetupSteps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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

        $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', $validator->validated()['targets'])), fn (string $t) => $t !== '')));
        $unknown = array_values(array_diff($targets, ReleaseCatalog::TARGETS));
        if ($unknown !== []) {
            return $this->validationFailed(['targets' => 'Unknown target(s): '.implode(', ', array_map(fn ($t) => mb_substr($t, 0, 30), $unknown)).'. Allowed: '.implode(', ', ReleaseCatalog::TARGETS).'.']);
        }

        // Egen transaktion (transactional() är false): jobbet köas först efter commit, så att varken
        // jobbet eller dess unikhetslås finns kvar för en ändring som rullades tillbaka.
        try {
            $refused = DB::transaction(fn () => $this->save($game, $targets));
        } catch (UniqueConstraintViolationException) {
            // Ett annat anrop hann ta samma bundle-id mellan kontrollen och sparandet (unikt index).
            return self::bundleIdTaken(BundleId::forSlug($game->slug));
        }
        if ($refused !== null) {
            return $refused;
        }

        if (in_array('app_store', $targets, true)) {
            RunAppStoreSetup::dispatch($game->id);
        }

        $game = $game->fresh();
        $status = ReleaseStatus::forGame($game);
        $open = [];
        foreach ($targets as $target) {
            foreach (SetupSteps::open($game, $target) as $step) { // samma ordning som spärren och get_release_status
                $open[] = ['target' => $step->target, 'key' => $step->key, 'instruction' => $step->instruction, 'last_error' => $step->last_error];
            }
        }

        return $this->json([
            'saved' => true,
            'release_targets' => $game->releaseTargets(),
            'open_setup_steps' => $open,
            'next' => $status['next'],
        ]);
    }

    // Körs i transaktionen. Ett nekande svar returneras innan något har skrivits.
    private function save(Game $game, array $targets): ?Response
    {
        // Spelets rad låses, samma lås som ReleaseService::promote tar, så att målen inte ändras mitt
        // under en befordran.
        $game = Game::query()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

        foreach (array_diff($game->releaseTargets(), $targets) as $target) {
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

        $game->forceFill(['release_targets' => $targets])->save();
        SetupSteps::sync($game);

        return null;
    }

    // Verktyget köar ett jobb; GameTools allmänna transaktion används inte (se run()).
    protected function transactional(): bool
    {
        return false;
    }

    private static function bundleIdTaken(string $bundle): Response
    {
        return Response::error("Bundle id {$bundle} is already used by another game. Ask Erik to choose another bundle id for this game.");
    }
}
