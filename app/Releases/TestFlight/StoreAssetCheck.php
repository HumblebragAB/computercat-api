<?php

namespace App\Releases\TestFlight;

use App\Models\Game;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;

// Varnar när spelets appikon eller startbild fortfarande är Capacitors standardbild (faktabanken:
// process-butiksresurser). Stoppar aldrig ett släpp: ett internt TestFlight-bygge med standardikonen
// fungerar, men det är fel inför App Store. Körs efter att släppet är sparat, utan transaktion.
class StoreAssetCheck
{
    public const ICON_DIR = 'ios/App/App/Assets.xcassets/AppIcon.appiconset';

    public const SPLASH_DIR = 'ios/App/App/Assets.xcassets/Splash.imageset';

    // Git-blobbens sha1 för bilderna som Capacitor 8.5.3 (cap add ios --packagemanager SPM) skriver.
    // Avlästa 2026-10-20 i Pawtenders och Reveals commit "iOS-projekt (Capacitor)": samma värden i båda.
    // Byter byggguiden Capacitor-version ska de läsas av på nytt (StoreAssetCheckTest).
    public const DEFAULT_ICON = 'adf6ba01dbe256605c5152ac1fd78ae99aaa2a8d';

    public const DEFAULT_SPLASH = '33ea6c970f2df1db62a624a55e5bbcc4ee07bbdf';

    public function __construct(private GitHubAppClient $github) {}

    /** @return list<string> tom lista när ikon och startbild är spelets egna */
    public function warnings(Game $game, string $commit): array
    {
        $short = substr($commit, 0, 7);
        try {
            $icon = $this->github->listDirectory($game->slug, self::ICON_DIR, $commit);
            $splash = $this->github->listDirectory($game->slug, self::SPLASH_DIR, $commit);
        } catch (ExternalApiException $e) {
            return ["Could not check the app icon and launch screen at commit {$short}: {$e->getMessage()} See get_fact process-butiksresurser and check them yourself."];
        }

        $warnings = [];
        if ($icon === null) {
            $warnings[] = 'Commit '.$short.' has no '.self::ICON_DIR.'. The iOS project is created with scripts/ios-setup.mjs (process-ios-capacitor); then replace the icon as get_fact process-butiksresurser says.';
        } elseif (self::hasBlob($icon, self::DEFAULT_ICON)) {
            $warnings[] = "The app icon at commit {$short} is still Capacitor's default (".self::ICON_DIR.'/AppIcon-512@2x.png). Replace it with the game\'s own opaque 1024x1024 PNG without alpha before the next version; see get_fact process-butiksresurser.';
        }
        if ($splash === null) {
            $warnings[] = 'Commit '.$short.' has no '.self::SPLASH_DIR.'. See get_fact process-butiksresurser.';
        } elseif (self::hasBlob($splash, self::DEFAULT_SPLASH)) {
            $warnings[] = "The launch screen at commit {$short} still shows Capacitor's logo (".self::SPLASH_DIR.'). Replace the three 2732x2732 PNGs with the game\'s own image before the next version; see get_fact process-butiksresurser.';
        }

        return $warnings;
    }

    /** @param list<array{name: string, type: string, sha: string}> $entries */
    private static function hasBlob(array $entries, string $sha): bool
    {
        foreach ($entries as $entry) {
            if ($entry['type'] === 'file' && $entry['sha'] === $sha) {
                return true;
            }
        }

        return false;
    }
}
