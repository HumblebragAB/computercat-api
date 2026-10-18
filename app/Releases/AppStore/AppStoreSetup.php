<?php

namespace App\Releases\AppStore;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\SafeMessage;
use App\Releases\SetupSteps;
use Illuminate\Support\Facades\Log;
use Throwable;

// Computercats engångssteg för app_store, i definitionsordning, en enhet arbete (högst fyra anrop)
// per runOne(), så att jobbet håller sig under köns retry_after. Varje steg letar först och skapar
// sedan, så att en omkörning aldrig skapar dubbletter. Ett klart steg utan sitt id öppnas igen (R17).
// Testare är användar-id; e-post hämtas per testare och loggas aldrig (R18). Inga HTTP-anrop i transaktioner.
//
// Eriks beslut 2026-10-18: gruppen räcker för första bygget. Testarna läggs till i ett eget steg
// (TESTERS) först när spelet har ett giltigt TestFlight-bygge, eftersom den enda bevisade vägen
// (spike B9) hade ett VALID bygge i gruppen; steget stoppar inget släpp. Ett steg som faller får
// last_error och väntar allt längre mellan försöken (failed_attempts, retry_at; QueueAppStoreSetup).
final class AppStoreSetup
{
    public const PROGRESS = 'progress';

    public const IDLE = 'idle';

    public const TESTERS = 'appstore_testflight_testers';

    /** Väntetid efter första felet; den dubblas per fel upp till ett dygn. */
    public const BACKOFF_FIRST_MINUTES = 10;

    public const BACKOFF_MAX_MINUTES = 1440;

    /** Steg => id:t som steget ger Computercat. */
    public const STEP_IDS = [
        'appstore_bundle_id' => 'bundle_resource_id',
        'appstore_profile' => 'profile_id',
        'appstore_create_app' => 'app_id',
        'appstore_testflight_group' => 'beta_group_id',
    ];

    public function __construct(private AppStoreConnectApi $asc) {}

    /** Steg som Computercat själv gör eller kontrollerar mot Apple. @return list<string> */
    public static function automaticKeys(): array
    {
        return [...array_keys(self::STEP_IDS), self::TESTERS];
    }

    public static function backoffMinutes(int $failedAttempts): int
    {
        return (int) min(self::BACKOFF_MAX_MINUTES, self::BACKOFF_FIRST_MINUTES * 2 ** max(0, min($failedAttempts - 1, 20)));
    }

    /** Ett giltigt bygge finns när ett TestFlight-släpp lyckats (bygget VALID och i gruppen, TestFlightBuilder). */
    public static function hasValidBuild(Game $game): bool
    {
        return Release::where('game_id', $game->id)->where('target', 'app_store')->where('stage', 'testflight')
            ->where('status', Release::SUCCEEDED)->exists();
    }

    /**
     * Ska schemat köa spelet? Bara databasen. Steget som runOne skulle göra först avgör: väntar det
     * på backoff, eller är det testarna utan giltigt bygge, köas inget (inga anrop var tionde minut).
     */
    public static function isDue(Game $game): bool
    {
        $step = SetupSteps::open($game, 'app_store')->first(fn (ReleaseSetupStep $s) => in_array($s->key, self::automaticKeys(), true));
        if ($step === null || ($step->retry_at !== null && $step->retry_at->isFuture())) {
            return false;
        }

        return $step->key !== self::TESTERS || self::hasValidBuild($game) || self::pendingTesters($game) === [];
    }

    /** Kör enheter tills inget mer går att göra (tester och Filament). @return list<string> steg som blev klara */
    public function run(Game $game, int $maxUnits = 50): array
    {
        $done = [];
        for ($i = 0; $i < $maxUnits; $i++) {
            [$outcome, $finished] = $this->runOne($game->refresh());
            if ($finished !== null) {
                $done[] = $finished;
            }
            if ($outcome === self::IDLE) {
                break;
            }
        }

        return $done;
    }

    /** @return array{0: string, 1: string|null} [PROGRESS|IDLE, steget som blev klart] */
    public function runOne(Game $game): array
    {
        if (! in_array('app_store', $game->releaseTargets(), true) || $game->bundle_id === null) {
            return [self::IDLE, null];
        }
        self::reopenStepsWithoutIds($game);

        foreach (array_keys(SetupSteps::DEFINITIONS['app_store']) as $key) {
            $step = ReleaseSetupStep::where('game_id', $game->id)->where('target', 'app_store')->where('key', $key)->first();
            if ($step === null || $step->status === ReleaseSetupStep::DONE || ! in_array($key, self::automaticKeys(), true)) {
                continue; // klart, eller Eriks steg som inte kan kontrolleras via API (integritet, åldersgräns)
            }

            try {
                $result = match ($key) {
                    'appstore_bundle_id' => $this->bundleId($game),
                    'appstore_profile' => $this->profile($game),
                    'appstore_create_app' => $this->detectApp($game),
                    'appstore_testflight_group' => $this->group($game),
                    self::TESTERS => $this->testers($game),
                };
            } catch (Throwable $e) {
                // Backoff i stället för ett nytt försök var tionde minut med samma fel (schemat läser retry_at).
                $attempts = (int) $step->failed_attempts + 1;
                $step->update([
                    'last_error' => $e instanceof SafeMessage ? $e->getMessage() : 'Internal error ('.class_basename($e).').',
                    'failed_attempts' => $attempts,
                    'retry_at' => now()->addMinutes(self::backoffMinutes($attempts)),
                ]);
                throw $e;
            }

            if ($result === 'wait') {
                return [self::IDLE, null]; // appen finns inte än (gruppen kräver den), eller inget giltigt bygge än (testarna)
            }
            if ($result === 'more') {
                return [self::PROGRESS, null];
            }

            $step->update(['status' => ReleaseSetupStep::DONE, 'done_by' => null, 'done_at' => now(), 'last_error' => null, 'failed_attempts' => 0, 'retry_at' => null]);
            Log::notice('Engångssteg klart via App Store Connect', ['game' => $game->slug, 'step' => $key]);

            return [self::PROGRESS, $key];
        }

        return [self::IDLE, null];
    }

    // R17: anropas också av migrationen 2026_10_17_100002 (bara databasen).
    public static function reopenStepsWithoutIds(Game $game): int
    {
        $reopened = 0;
        foreach (self::STEP_IDS as $key => $idKey) {
            if ($game->appStoreId($idKey) !== null) {
                continue;
            }
            $count = ReleaseSetupStep::where('game_id', $game->id)->where('target', 'app_store')->where('key', $key)
                ->where('status', ReleaseSetupStep::DONE)
                ->update(['status' => ReleaseSetupStep::OPEN, 'done_by' => null, 'done_at' => null, 'last_error' => "Öppnat igen: {$idKey} saknas hos Computercat.", 'updated_at' => now()]);
            if ($count > 0) {
                Log::warning('Engångssteg öppnat igen: id saknas', ['game' => $game->slug, 'step' => $key, 'id' => $idKey]);
                $reopened += $count;
            }
        }

        return $reopened;
    }

    private function bundleId(Game $game): string
    {
        $found = $this->asc->findBundleId($game->bundle_id)
            ?? $this->asc->createBundleId($game->bundle_id, 'ComputerCat '.str_replace('-', ' ', $game->slug));
        $this->remember($game, ['bundle_resource_id' => $found['id']]);

        return 'done';
    }

    // R13: en profil som inte är ACTIVE förnyas inte här; felet syns på steget och i Slack.
    private function profile(Game $game): string
    {
        $bundle = $game->appStoreId('bundle_resource_id') ?? throw new AppStoreSetupFailed('The bundle id is not registered yet.');
        $name = 'ComputerCat '.str_replace('-', ' ', $game->slug).' App Store';

        $profile = $this->asc->findProfile($name);
        if ($profile !== null && $profile['state'] !== 'ACTIVE') {
            throw new AppStoreSetupFailed("Profile \"{$name}\" exists but is {$profile['state']}; Erik must remove or renew it in the developer portal.");
        }
        $profile ??= $this->asc->createProfile($name, $bundle, AppStoreConfig::certificateId());
        $this->remember($game, ['profile_id' => $profile['id']]);

        return 'done';
    }

    private function detectApp(Game $game): string
    {
        $app = $this->asc->findApp($game->bundle_id);
        if ($app === null) {
            return 'wait';
        }
        $this->remember($game, ['app_id' => $app['id'], 'app_name' => $app['name']]);

        return 'done';
    }

    // Gruppen är det enda TestFlightBuilder behöver (beta_group_id). Finns id:t är steget klart utan anrop;
    // det gäller också spel där steget stod öppet och fastnade på testarna före 2026-10-18.
    private function group(Game $game): string
    {
        $appId = $game->appStoreId('app_id') ?? throw new AppStoreSetupFailed('The app does not exist in App Store Connect yet.');

        if ($game->appStoreId('beta_group_id') !== null) {
            return 'done';
        }

        $group = $this->asc->findBetaGroup($appId, AppStoreConfig::betaGroup())
            ?? $this->asc->createInternalBetaGroup($appId, AppStoreConfig::betaGroup());
        // En ny grupp har inga av de gamla testarna: testarsteget börjar om.
        $this->remember($game, ['beta_group_id' => $group['id'], 'testers_added' => [], 'tester_conflict' => [], 'tester_failed' => []]);
        ReleaseSetupStep::where('game_id', $game->id)->where('target', 'app_store')->where('key', self::TESTERS)
            ->where('status', ReleaseSetupStep::DONE)->update(['status' => ReleaseSetupStep::OPEN, 'done_by' => null, 'done_at' => null, 'updated_at' => now()]);

        return 'done';
    }

    /** @return list<string> testare i konfigen som inte är tillagda */
    private static function pendingTesters(Game $game): array
    {
        $added = (array) (($game->app_store_ids ?? [])['testers_added'] ?? []);

        return array_values(array_filter(AppStoreConfig::testerUserIds(), fn (string $id) => ! in_array($id, $added, true)));
    }

    // En testare per enhet, högst fyra anrop: användaren (e-post, allAppsVisible), gruppens medlemmar,
    // visibleApps (bara för begränsad åtkomst) och skapandet. Ett 409 läses tillbaka i nästa enhet:
    // finns testaren i gruppen är hen klar, annars fail loud. En testare som inte går att lägga till
    // stoppar inte de andra; när alla är prövade kastas ett samlat fel (backoff) och nästa körning
    // försöker igen med dem som föll.
    private function testers(Game $game): string
    {
        $pending = self::pendingTesters($game);
        if ($pending === []) {
            return 'done';
        }
        if (! self::hasValidBuild($game)) {
            return 'wait';
        }
        $appId = $game->appStoreId('app_id') ?? throw new AppStoreSetupFailed('The app does not exist in App Store Connect yet.');
        $groupId = $game->appStoreId('beta_group_id') ?? throw new AppStoreSetupFailed('The TestFlight group does not exist yet.');

        $ids = $game->app_store_ids ?? [];
        $added = (array) ($ids['testers_added'] ?? []);
        $conflict = (array) ($ids['tester_conflict'] ?? []);
        $failed = (array) ($ids['tester_failed'] ?? []);

        foreach ($pending as $userId) {
            if (array_key_exists($userId, $failed)) {
                continue;
            }

            return $this->tester($game, $userId, $appId, $groupId, $added, $conflict, $failed);
        }

        // Alla prövade. De som föll (och fortfarande står i konfigen) prövas igen vid nästa körning (backoff).
        $this->remember($game, ['tester_failed' => []]);
        throw new AppStoreSetupFailed(implode(' ', array_intersect_key($failed, array_flip($pending))));
    }

    // Ett fel som gäller just den här testaren (4xx på användaren, visibleApps eller skapandet, eller
    // AppStoreSetupFailed) stoppar inte de andra: det sparas med användar-id och status, aldrig e-post.
    // Anslutningsfel, 5xx, 401 och rate limit gäller alla anrop och kastas vidare (backoff för steget).
    private function testerFailed(Game $game, string $userId, array $failed, AppStoreSetupFailed|ExternalApiException $e): string
    {
        if ($e instanceof ExternalApiException) {
            if ($e->status < 400 || $e->status >= 500 || in_array($e->status, [401, 429], true) || $e->rateLimited) {
                throw $e;
            }
            $reason = "App Store Connect svarade {$e->status} för användaren {$userId}; kontrollera hen i App Store Connect eller ta bort hen ur releases.app_store.tester_user_ids.";
        } else {
            $reason = $e->getMessage();
        }
        $failed[$userId] = $reason;
        $this->remember($game, ['tester_failed' => $failed]);
        Log::warning('TestFlight-testare kunde inte läggas till', ['game' => $game->slug, 'user_id' => $userId, 'status' => $e instanceof ExternalApiException ? $e->status : null]);

        return 'more';
    }

    /** En enhet för en testare (högst fyra anrop). @return string 'more' */
    private function tester(Game $game, string $userId, string $appId, string $groupId, array $added, array $conflict, array $failed): string
    {
        try {
            $user = $this->asc->user($userId); // e-posten registreras hos Redactor (R18)
        } catch (ExternalApiException $e) {
            return $this->testerFailed($game, $userId, $failed, $e);
        }
        $withoutConflict = array_values(array_diff($conflict, [$userId]));

        // Gruppens medlemslista gäller alla testare: ett fel här kastas vidare.
        if ($this->asc->betaGroupHasTester($groupId, $user['email'])) {
            $this->remember($game, ['testers_added' => [...$added, $userId], 'tester_conflict' => $withoutConflict]);
            Log::notice('TestFlight-testare i gruppen', ['game' => $game->slug, 'user_id' => $userId]);

            return 'more';
        }

        if (in_array($userId, $conflict, true)) {
            $failed[$userId] = "App Store Connect vägrar lägga användaren {$userId} i TestFlight-gruppen (409) och hen finns inte i gruppen; kontrollera att hen har tillgång till appen och en roll som får testa internt, eller ta bort hen ur releases.app_store.tester_user_ids.";
            $this->remember($game, ['tester_conflict' => $withoutConflict, 'tester_failed' => $failed]);

            return 'more';
        }

        try {
            $sees = $user['all_apps_visible'] || $this->asc->userCanSeeApp($userId, $appId);
        } catch (AppStoreSetupFailed|ExternalApiException $e) {
            return $this->testerFailed($game, $userId, $failed, $e);
        }
        if (! $sees) {
            $failed[$userId] = "Användaren {$userId} har inte tillgång till appen i App Store Connect; ge åtkomst eller ta bort hen ur releases.app_store.tester_user_ids.";
            $this->remember($game, ['tester_failed' => $failed]);

            return 'more';
        }

        try {
            $this->asc->createBetaTester($user['email'], $groupId);
        } catch (ExternalApiException $e) {
            if ($e->status !== 409) {
                return $this->testerFailed($game, $userId, $failed, $e);
            }
            // Apple dokumenterar inte vad 409 betyder här; medlemslistan avgör i nästa enhet.
            $this->remember($game, ['tester_conflict' => [...$withoutConflict, $userId]]);
            Log::warning('TestFlight-testare: 409, läses tillbaka', ['game' => $game->slug, 'user_id' => $userId]);

            return 'more';
        }
        $this->remember($game, ['testers_added' => [...$added, $userId], 'tester_conflict' => $withoutConflict]);
        Log::notice('TestFlight-testare i gruppen', ['game' => $game->slug, 'user_id' => $userId]);

        return 'more';
    }

    private function remember(Game $game, array $ids): void
    {
        $game->forceFill(['app_store_ids' => array_merge($game->app_store_ids ?? [], $ids)])->save();
    }
}
