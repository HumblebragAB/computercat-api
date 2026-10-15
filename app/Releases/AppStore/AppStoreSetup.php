<?php

namespace App\Releases\AppStore;

use App\Models\Game;
use App\Models\ReleaseSetupStep;
use App\Provisioning\SafeMessage;
use App\Releases\SetupSteps;
use Illuminate\Support\Facades\Log;
use Throwable;

// Computercats engångssteg för app_store, i definitionsordning, en enhet arbete (högst fyra anrop)
// per runOne(), så att jobbet håller sig under köns retry_after. Varje steg letar först och skapar
// sedan, så att en omkörning aldrig skapar dubbletter. Ett klart steg utan sitt id öppnas igen (R17).
// Testare är användar-id; e-post hämtas per testare och loggas aldrig (R18). Inga HTTP-anrop i transaktioner.
final class AppStoreSetup
{
    public const PROGRESS = 'progress';

    public const IDLE = 'idle';

    /** Steg => id:t som steget ger Computercat. */
    public const STEP_IDS = [
        'appstore_bundle_id' => 'bundle_resource_id',
        'appstore_profile' => 'profile_id',
        'appstore_create_app' => 'app_id',
        'appstore_testflight_group' => 'beta_group_id',
    ];

    public function __construct(private AppStoreConnectApi $asc) {}

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
            if ($step === null || $step->status === ReleaseSetupStep::DONE || ! isset(self::STEP_IDS[$key])) {
                continue; // klart, eller Eriks steg som inte kan kontrolleras via API (integritet, åldersgräns)
            }

            try {
                $result = match ($key) {
                    'appstore_bundle_id' => $this->bundleId($game),
                    'appstore_profile' => $this->profile($game),
                    'appstore_create_app' => $this->detectApp($game),
                    'appstore_testflight_group' => $this->group($game),
                };
            } catch (Throwable $e) {
                $step->update(['last_error' => $e instanceof SafeMessage ? $e->getMessage() : 'Internal error ('.class_basename($e).').']);
                throw $e;
            }

            if ($result === 'wait') {
                return [self::IDLE, null]; // appen finns inte än; gruppen kräver den
            }
            if ($result === 'more') {
                return [self::PROGRESS, null];
            }

            $step->update(['status' => ReleaseSetupStep::DONE, 'done_by' => null, 'done_at' => now(), 'last_error' => null]);
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

    // Enheter: (1) hitta eller skapa gruppen, (2) en testare per enhet (högst fyra anrop), (3) klart.
    private function group(Game $game): string
    {
        $appId = $game->appStoreId('app_id') ?? throw new AppStoreSetupFailed('The app does not exist in App Store Connect yet.');

        $groupId = $game->appStoreId('beta_group_id');
        if ($groupId === null) {
            $group = $this->asc->findBetaGroup($appId, AppStoreConfig::betaGroup())
                ?? $this->asc->createInternalBetaGroup($appId, AppStoreConfig::betaGroup());
            $this->remember($game, ['beta_group_id' => $group['id']]);

            return 'more';
        }

        $added = (array) (($game->app_store_ids ?? [])['testers_added'] ?? []);
        foreach (AppStoreConfig::testerUserIds() as $userId) {
            if (in_array($userId, $added, true)) {
                continue;
            }
            $email = $this->asc->userEmail($userId); // registreras hos Redactor (R18)
            if ($this->asc->findBetaTester($email, $groupId) === null) {
                $existing = $this->asc->findBetaTester($email);
                $existing !== null
                    ? $this->asc->addTestersToGroup($groupId, [$existing['id']])
                    : $this->asc->createBetaTester($email, $groupId);
            }
            $this->remember($game, ['testers_added' => array_values(array_merge($added, [$userId]))]);
            Log::notice('TestFlight-testare i gruppen', ['game' => $game->slug, 'user_id' => $userId]);

            return 'more';
        }

        return 'done';
    }

    private function remember(Game $game, array $ids): void
    {
        $game->forceFill(['app_store_ids' => array_merge($game->app_store_ids ?? [], $ids)])->save();
    }
}
