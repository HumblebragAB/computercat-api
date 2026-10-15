<?php

namespace App\Releases\TestFlight;

use App\Models\Game;
use App\Models\Release;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\MarketingVersion;
use App\Releases\ReleaseRefused;
use App\Services\BuildGuide;

// Det promote_release kontrollerar i spelrepot innan ett TestFlight-släpp skapas (Eriks beslut
// 2026-10-15, plan R2–R4). Körs före transaktionen: inga HTTP-anrop under spelets lås. Svaren säger
// exakt vad som ska höjas, så att spelets Claude kan rätta utan att fråga.
class TestFlightPreflight
{
    private const IDS = ['bundle_resource_id', 'profile_id', 'app_id', 'beta_group_id'];

    public function __construct(private GitHubAppClient $github, private IosBuildGuides $guides) {}

    /** @throws ReleaseRefused */
    public function check(Game $game, string $commit): PreflightResult
    {
        foreach (self::IDS as $key) {
            if ($game->bundle_id === null || $game->appStoreId($key) === null) {
                throw new ReleaseRefused("App Store setup is not complete for this game (missing {$key}). Ask Erik to check the setup steps in Filament.");
            }
        }

        $short = substr($commit, 0, 7);
        try {
            $package = $this->github->getFile($game->slug, 'package.json', $commit);
            $claude = $this->github->getFile($game->slug, 'CLAUDE.md', $commit);
        } catch (ExternalApiException $e) {
            throw new ReleaseRefused("Could not read the game repository at commit {$short}: {$e->getMessage()} Try again in a minute.");
        }

        $json = $package === null ? null : json_decode($package['text'], true);
        $raw = is_array($json) ? ($json['version'] ?? null) : null;
        $version = MarketingVersion::parse($raw);
        if ($version === null) {
            throw new ReleaseRefused("package.json at commit {$short} has version ".mb_strimwidth((string) json_encode($raw, JSON_UNESCAPED_UNICODE), 0, 100, '…').'; use MAJOR.MINOR.PATCH (for example 1.0.0), run npm run check, push to main, wait for the Demo build and call promote_release again.');
        }

        $guide = $claude === null ? null : BuildGuide::versionFromClaudeMd($claude['text']);
        if ($guide === null || ! $this->guides->supports($guide)) {
            throw new ReleaseRefused("Commit {$short} is on build guide ".($guide ?? '(unknown)').', which cannot be built for iOS. Move the game to the latest build guide (get_build_guide) before sending it to TestFlight.');
        }

        $last = self::lastReleased($game);
        if ($last !== null && ! $version->isHigherThan($last)) {
            throw new ReleaseRefused("package.json at commit {$short} has version {$version}, but {$last} is already released to TestFlight. Raise version in package.json above {$last} (for example {$last->nextPatch()}), run npm run check, push to main, wait for the Demo build and call promote_release again.");
        }

        try {
            $tag = $this->github->getTag($game->slug, 'v'.$version);
        } catch (ExternalApiException $e) {
            throw new ReleaseRefused("Could not read the game repository at commit {$short}: {$e->getMessage()} Try again in a minute.");
        }
        // R3: samma commit får försökas igen; en ny commit kräver en ny version. Taggen flyttas aldrig.
        if ($tag !== null && ($tag['type'] !== 'commit' || $tag['sha'] !== $commit)) {
            throw new ReleaseRefused("Tag v{$version} already exists on another commit in the game repository. Every new commit to TestFlight needs a new version: raise version in package.json (for example {$version->nextPatch()}), run npm run check, push to main, wait for the Demo build and call promote_release again.");
        }

        return new PreflightResult($version, $guide);
    }

    public static function lastReleased(Game $game): ?MarketingVersion
    {
        return MarketingVersion::highest(Release::where('game_id', $game->id)->where('target', 'app_store')
            ->where('stage', 'testflight')->where('status', Release::SUCCEEDED)->pluck('marketing_version'));
    }
}
