<?php

namespace Tests\Support;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\ReleaseCatalog;
use App\Releases\SetupSteps;
use App\Releases\TestFlight\IosBuildGuides;
use Illuminate\Support\Str;

// Gemensamma byggstenar för släpptesterna.
trait CreatesReleases
{
    protected function sha(string $char = 'a'): string
    {
        return str_repeat($char, 40);
    }

    protected function demoBuild(Game $game, ?string $sha = null, $deployedAt = null): DemoBuild
    {
        return DemoBuild::create([
            'game_id' => $game->id,
            'commit_sha' => $sha ?? $this->sha(),
            'deployment_uuid' => (string) Str::uuid(),
            'deployed_at' => $deployedAt ?? now(),
        ]);
    }

    protected function release(Game $game, array $attrs = []): Release
    {
        return Release::create(array_merge([
            'game_id' => $game->id,
            'target' => 'web',
            'stage' => 'web_production',
            'commit_sha' => $this->sha(),
            'status' => Release::REQUESTED,
        ], $attrs));
    }

    /** Aktivt spel med mål och med alla engångssteg satta som klara. */
    protected function releasableGame(string $slug = 'kattsvep', array $targets = ['web']): Game
    {
        $game = Game::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_active' => true, 'settings' => []]);
        $game->forceFill(['release_targets' => $targets, 'is_active' => true, 'releases_enabled' => true])->save();
        SetupSteps::sync($game);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => ReleaseSetupStep::DONE]);

        return $game->fresh();
    }

    /** Produktionsstegen är stängda i 1a (R1); tester av godkännande och spärrar öppnar dem via konfigen. */
    protected function openProductionStages(): void
    {
        config(['releases.buildable_stages' => ReleaseCatalog::PROMOTABLE_STAGES]);
    }

    protected function ownerOf(Game $game): User
    {
        $user = User::factory()->staff()->create();
        $game->members()->attach($user, ['role' => 'owner']);

        return $user;
    }

    protected function adminUser(): User
    {
        return User::factory()->staff(true)->create();
    }

    /** Låtsas att de här byggguideversionerna stöder iOS (före v3). */
    protected function iosGuides(string ...$versions): void
    {
        $this->app->instance(IosBuildGuides::class, new class($versions) extends IosBuildGuides
        {
            public function __construct(private array $versions) {}

            public function supports(string $version): bool
            {
                return in_array($version, $this->versions, true);
            }
        });
    }
}
