<?php

namespace App\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\TestFlight\TestFlightPreflight;

// Det get_release_status svarar (R11: bara databasen, inga externa anrop).
final class ReleaseStatus
{
    public static function forGame(Game $game): array
    {
        $targets = $game->releaseTargets();
        $demo = DemoBuild::latestFor($game);
        $disabled = ReleaseSwitch::disabledReason($game);

        $perTarget = [];
        foreach ($targets as $target) {
            $perTarget[$target] = self::forTarget($game, $target, $demo, $disabled);
        }

        if ($disabled !== null) {
            $next = ReleaseNext::for('releases_disabled');
        } elseif ($targets === []) {
            $next = ReleaseNext::for('no_targets');
        } else {
            $next = collect($perTarget)->map(fn (array $t, string $name) => "[{$name}] {$t['next']}")->implode(' ');
        }

        return [
            'game' => $game->slug,
            'releases_enabled' => $disabled === null,
            'release_targets' => $targets,
            'demo' => $demo === null ? null : ['commit_sha' => $demo->commit_sha, 'deployed_at' => $demo->deployed_at->toIso8601String()],
            'targets' => $perTarget,
            'next' => $next,
        ];
    }

    /** @return array{state: string, next: string} */
    public static function describe(Release $release): array
    {
        [$state, $vars] = self::releaseState($release);

        return ['state' => $state, 'next' => ReleaseNext::for($state, $vars)];
    }

    private static function forTarget(Game $game, string $target, ?DemoBuild $demo, ?string $disabled): array
    {
        $steps = ReleaseSetupStep::where('game_id', $game->id)->where('target', $target)->orderBy('id')->get();
        $test = ReleaseCatalog::testStage($target);
        $prod = ReleaseCatalog::productionStage($target);
        // Öppna steg som stoppar nästa steg i flödet (teststeget, eller produktionen när testet är Demo).
        $open = SetupSteps::openFor($game, $target, $test === 'demo' ? $prod : $test);
        $testRelease = $test === 'demo' ? null : self::latest($game, $target, $test);
        $prodRelease = self::latest($game, $target, $prod);

        [$state, $vars] = match (true) {
            $disabled !== null => ['releases_disabled', []],
            $open !== null => ['setup_open', ['instruction' => $open->instruction]],
            $demo === null => ['no_demo_build', []],
            default => self::flowState($game, $target, $demo, $testRelease, $prodRelease, $test, $prod),
        };

        $stages = [];
        if ($test !== 'demo') {
            $stages[$test] = ['latest_release' => self::present($testRelease)];
        }
        $stages[$prod] = ['latest_release' => self::present($prodRelease)];

        return [
            'state' => $state,
            'next' => ReleaseNext::for($state, $vars),
            // last_error är redan redigerat (ReleaseSetupStep::saving); next_attempt_at är backoffen för ett fallet
            // steg, null när den passerat (då körs steget vid nästa schemalagda körning).
            'setup_steps' => $steps->map(fn (ReleaseSetupStep $s) => [
                'key' => $s->key, 'status' => $s->status, 'instruction' => $s->instruction,
                'last_error' => $s->status === ReleaseSetupStep::OPEN ? $s->last_error : null,
                'next_attempt_at' => $s->status === ReleaseSetupStep::OPEN && $s->retry_at?->isFuture() ? $s->retry_at->toIso8601String() : null,
            ])->values()->all(),
            'stages' => $stages,
        ] + ($target === 'app_store' ? ['ios' => [
            'bundle_id' => $game->bundle_id,
            'last_released_version' => ($last = TestFlightPreflight::lastReleased($game)) === null ? null : (string) $last,
            'last_build_number' => (int) $game->ios_build_number,
        ]] : []);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function flowState(Game $game, string $target, DemoBuild $demo, ?Release $testRelease, ?Release $prodRelease, string $test, string $prod): array
    {
        // Något som pågår går först: det är det Claude ska vänta på.
        foreach ([$prodRelease, $testRelease] as $release) {
            if ($release !== null && $release->isActive()) {
                return self::releaseState($release);
            }
        }

        // Allt räknas mot Demo-bygget som gäller nu: kommer en nyare commit i Demo börjar flödet om för den.
        $commit = $demo->commit_sha;
        if ($test !== 'demo') {
            $testForCommit = self::latest($game, $target, $test, $commit);
            if ($testForCommit === null) {
                return $test === 'testflight'
                    ? ['ready_for_testflight', ['commit' => $commit, 'last_version' => (string) (TestFlightPreflight::lastReleased($game) ?? 'ingen')]]
                    : ['ready_for_test', ['commit' => $commit, 'stage' => $test, 'target' => $target]];
            }
            if ($testForCommit->status !== Release::SUCCEEDED) { // misslyckat eller avbrutet
                return self::releaseState($testForCommit);
            }
            // Etapp 2 del 1: App Store är stängt; ett lyckat TestFlight-släpp är slutet tills nästa version.
            if ($test === 'testflight' && ! ReleaseCatalog::isBuildable($prod)) {
                return self::releaseState($testForCommit);
            }
        }

        $prodForCommit = self::latest($game, $target, $prod, $commit);
        if ($prodForCommit !== null) {
            return self::releaseState($prodForCommit);
        }

        if (($prodOpen = SetupSteps::openFor($game, $target, $prod)) !== null && ReleaseCatalog::isBuildable($prod)) {
            return ['setup_open', ['instruction' => $prodOpen->instruction]];
        }

        $testLabel = $test === 'demo' ? 'Demo' : $test;
        if (! ReleaseCatalog::isBuildable($prod)) { // R1
            return ['production_not_open', ['commit' => $commit, 'test_stage' => $testLabel, 'etapp' => ReleaseCatalog::BUILD_ETAPP[$prod] ?? '?']];
        }

        return ['ready_for_production', ['commit' => $commit, 'test_stage' => $testLabel, 'target' => $target, 'stage' => $prod]];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function releaseState(Release $release): array
    {
        $testflight = $release->stage === 'testflight';
        $state = match ($release->status) {
            Release::AWAITING_APPROVAL => 'awaiting_approval',
            Release::REQUESTED => ReleaseCatalog::isBuiltByComputercat($release->stage) ? 'starting' : 'queued_for_build',
            Release::BUILDING, Release::PROCESSING => $testflight ? 'building_testflight' : 'in_progress',
            Release::SUCCEEDED => $testflight ? 'succeeded_testflight' : 'succeeded',
            Release::FAILED => $testflight ? 'failed_testflight' : 'failed',
            Release::CANCELLED => 'cancelled',
        };

        return [$state, [
            'release' => $release->uuid,
            'commit' => $release->commit_sha,
            'status' => $release->status,
            'stage' => $release->stage,
            'target' => $release->target,
            'etapp' => ReleaseCatalog::BUILD_ETAPP[$release->stage] ?? '?',
            'error' => $release->last_error === null || $release->last_error === '' ? 'orsak saknas.' : rtrim($release->last_error, '.').'.',
            'version' => (string) ($release->marketing_version ?? '?'),
            'build' => (string) ($release->build_number ?? '?'),
            'phase' => $release->status === Release::PROCESSING ? 'Apple bearbetar bygget' : 'GitHub Actions bygger och laddar upp',
        ]];
    }

    private static function latest(Game $game, string $target, string $stage, ?string $commit = null): ?Release
    {
        return Release::where('game_id', $game->id)->where('target', $target)->where('stage', $stage)
            ->when($commit !== null, fn ($q) => $q->where('commit_sha', $commit))->orderByDesc('id')->first();
    }

    private static function present(?Release $release): ?array
    {
        if ($release === null) {
            return null;
        }

        return [
            'release' => $release->uuid,
            'commit_sha' => $release->commit_sha,
            'status' => $release->status,
            'approved' => $release->approved_at !== null,
            'self_approved' => $release->self_approved,
            'external' => $release->external,
            'marketing_version' => $release->marketing_version,
            'build_number' => $release->build_number,
            'last_error' => $release->last_error,
            'created_at' => $release->created_at->toIso8601String(),
        ];
    }
}
