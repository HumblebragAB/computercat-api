<?php

namespace App\Releases;

use App\Models\Game;
use App\Models\ReleaseSetupStep;

// Engångssteg per spel och butik (spec Del 1). Skapas när ett mål läggs till.
// play_first_aab saknas medvetet: det skapas först om etapp 3:s spike bekräftar kravet (R9).
final class SetupSteps
{
    /** @var array<string, array<string, string>> */
    public const DEFINITIONS = [
        'web' => [
            'web_public_address' => 'Erik sätter spelets publika webbadress (domänen) för produktionswebben.',
        ],
        'app_store' => [
            'appstore_bundle_id' => 'Computercat registrerar spelets bundle-id i App Store Connect via API (byggs i etapp 2).',
            'appstore_create_app' => 'Erik skapar appen i App Store Connect med spelets bundle-id.',
            'appstore_privacy_and_age' => 'Erik fyller i appens integritetsuppgifter och åldersgräns i App Store Connect.',
        ],
        'play_store' => [
            'play_create_app' => 'Erik skapar appen i Play Console med spelets paketnamn.',
            'play_store_listing' => 'Erik fyller i innehållsklassning, dataskydd, målgrupp och butikstext i Play Console.',
            'play_app_signing' => 'Erik aktiverar Play App Signing för appen i Play Console.',
        ],
    ];

    /** Steg som Computercat själv sätter som klara (etapp 2). De kan inte bockas av för hand i Filament. */
    public const AUTOMATIC = ['appstore_bundle_id'];

    /** Skapar saknade steg för spelets mål. Klara och öppna steg lämnas orörda; inget raderas. */
    public static function sync(Game $game): void
    {
        foreach ($game->releaseTargets() as $target) {
            foreach (self::DEFINITIONS[$target] ?? [] as $key => $instruction) {
                ReleaseSetupStep::firstOrCreate(
                    ['game_id' => $game->id, 'target' => $target, 'key' => $key],
                    ['status' => ReleaseSetupStep::OPEN, 'instruction' => $instruction],
                );
            }
        }
    }
}
