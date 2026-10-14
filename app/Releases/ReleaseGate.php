<?php

namespace App\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;

// Spärrarna 1, 2, 5, 6, 8 och 9 (spec Del 1). Samma kontroll körs när ett släpp
// skapas, godkänns och startas (spärr 7). Spärr 3 (godkännande) och 4 (lås och
// unikt index) ligger i ReleaseService, eftersom de kräver transaktionen.
final class ReleaseGate
{
    /** @return string|null skälet till att släppet inte får gå vidare, annars null */
    public static function check(Game $game, string $target, string $stage, ?string $commit): ?string
    {
        if (! $game->is_active) {
            return 'The game is not active (archived games cannot be released).';
        }

        if (($off = ReleaseSwitch::disabledReason($game)) !== null) { // 9
            return $off;
        }

        if (! in_array($target, $game->releaseTargets(), true)) { // 5
            return "Target {$target} is not enabled for this game; ask a platform admin to run set_release_targets.";
        }

        if (! ReleaseCatalog::isPromotable($target, $stage)) {
            return "Stage {$stage} cannot be promoted for target {$target}.";
        }

        // R1: produktionsstegen är stängda tills deras bygge finns, så att Erik aldrig får en
        // godkännandebegäran för något som inte kan byggas.
        if (! ReleaseCatalog::isBuildable($stage)) {
            return 'Produktion byggs i etapp '.(ReleaseCatalog::BUILD_ETAPP[$stage] ?? '?')."; {$stage} cannot be requested yet.";
        }

        $open = ReleaseSetupStep::where('game_id', $game->id)->where('target', $target)
            ->where('status', ReleaseSetupStep::OPEN)->orderBy('id')->first();
        if ($open !== null) { // 6
            return "Setup is not finished for {$target}: {$open->instruction}";
        }

        $production = ReleaseCatalog::isProduction($target, $stage);
        if ($commit === null) { // 8 (produktion) och tomt Demo-läge (test)
            return $production
                ? 'commit_sha is required for production stages.'
                : 'No successful Demo build exists yet; push to main and wait for get_provisioning_status to show demo.state live.';
        }
        if (! ReleaseCatalog::isCommit($commit)) {
            return 'commit_sha must be a full 40-character lower-case hex SHA.';
        }

        $test = ReleaseCatalog::testStage($target);
        $hasReceipt = DemoBuild::where('game_id', $game->id)->where('commit_sha', $commit)->exists();

        if (! $production) { // 1
            return $hasReceipt ? null : "Commit {$commit} has no successful Demo build.";
        }

        // 2
        if ($test === 'demo') {
            return $hasReceipt ? null : "Commit {$commit} has no successful Demo build, so it cannot go to {$stage}.";
        }

        $tested = Release::where('game_id', $game->id)->where('target', $target)->where('stage', $test)
            ->where('commit_sha', $commit)->where('status', Release::SUCCEEDED)->exists();

        return $tested ? null : "Commit {$commit} has not succeeded in {$test}; promote it there first.";
    }
}
