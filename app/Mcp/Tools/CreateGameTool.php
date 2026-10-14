<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\Saga;
use App\Rules\GameSlug;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Startar provisioneringssagan och svarar direkt. Slugen reserveras i runnen;
// spelet skapas först när repot finns (steget game).
class CreateGameTool extends PlatformTool
{
    protected string $name = 'create_game';

    protected string $description = 'Create a new game: an empty private GitHub repo HumblebragAB/<slug> with README.md (the idea) and CLAUDE.md from the build guide, the game in Computercat (active, not publicly listed, you as owner), a Demo site at https://<slug>.apps.humblebrag.se behind basic auth that deploys on every push to main, and monitoring. No code is pushed: the game repo then follows CLAUDE.md (get_build_guide) and its first push builds the Demo. Returns immediately with a run id; follow up with get_provisioning_status. Limits: 15 Demo games in total and 5 new games per user per 24 hours.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('3-40 characters: lowercase letters, digits and single hyphens, starting with a letter. Becomes repo name and subdomain.')->required(),
            'name' => $schema->string()->description('Display name of the game, max 100 characters.')->required(),
            'idea' => $schema->string()->description('The game idea, max 4000 characters. Written to the README of the new repo.')->required(),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $missing = ProvisioningConfig::missing();
        if ($missing !== []) {
            return Response::error('Provisioning is not configured on the server. Missing: '.implode(', ', $missing).'. Nothing was created.');
        }

        $validator = Validator::make($args, [
            'slug' => ['required', 'string', new GameSlug],
            'name' => ['required', 'string', 'max:100'],
            'idea' => ['required', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }
        $data = $validator->validated();

        // Kontroll och reservation under ett lås, så att två samtidiga anrop
        // inte båda ryms under taket. slug_lock är dessutom unik i databasen.
        try {
            $run = Cache::lock('provisioning:create-game', 10)->block(5, fn () => $this->reserve($data, $user));
        } catch (LockTimeoutException) {
            return Response::error('Another create_game call is being processed. Nothing was created; try again in a few seconds.');
        }
        if ($run instanceof Response) {
            return $run;
        }

        try {
            Saga::start($run);
        } catch (\Throwable $e) {
            // Annars står runnen kvar som running utan jobb, håller slugen och
            // räknas mot taket. failed går att fortsätta med retry_provisioning.
            $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => 'saga: could not be started. Use retry_provisioning.']);

            throw $e;
        }

        return $this->json([
            'run' => $run->uuid,
            'slug' => $run->slug,
            'status' => $run->status,
            'repo' => ProvisioningConfig::repoFullName($run->slug),
            'demo_url' => ProvisioningConfig::demoUrl($run->slug),
            'next' => "Call get_provisioning_status with game \"{$run->slug}\" until status is \"succeeded\" (usually 1-3 minutes). The repo is then ready for code: open {$run->slug} in a Claude Code session and follow CLAUDE.md step 1 (get_build_guide, write the files, run the checks, push to main); the first push builds the Demo. The game appears in list_games once the step \"game\" is done.",
        ]);
    }

    private function reserve(array $data, User $user): ProvisioningRun|Response
    {
        if (Game::where('slug', $data['slug'])->exists()) {
            return Response::error('Slug is already used by a game in Computercat. Choose another slug.');
        }

        if (ProvisioningRun::where('slug_lock', $data['slug'])->exists()) {
            return Response::error('Slug is reserved by another provisioning run. Choose another slug.');
        }

        $max = (int) config('provisioning.max_demo_games');
        if (ProvisioningRun::countingTowardsCap()->count() >= $max) {
            return Response::error("The limit of {$max} Demo games is reached. Archive a Demo game first (archive_game) or ask Erik.");
        }

        $perDay = (int) config('provisioning.max_new_per_day');
        if (ProvisioningRun::provision()->where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->count() >= $perDay) {
            return Response::error("You have created {$perDay} games in the last 24 hours. Try again later.");
        }

        try {
            return ProvisioningRun::create([
                'slug' => $data['slug'],
                'slug_lock' => $data['slug'],
                'name' => $data['name'],
                'user_id' => $user->id,
                'kind' => ProvisioningRun::KIND_PROVISION,
                'status' => ProvisioningRun::RUNNING,
                'idea' => $data['idea'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return Response::error('Slug is reserved by another provisioning run. Choose another slug.');
        }
    }
}
