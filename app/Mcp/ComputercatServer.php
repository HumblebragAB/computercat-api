<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class ComputercatServer extends Server
{
    protected string $name = 'Computercat';

    protected string $version = '1.0.0';

    protected string $instructions = 'Configure Computercat games: leaderboards, achievements, content pools, remote config, events and allowed origins. Create new Demo games with create_game and follow them with get_provisioning_status. A new game repo gets its base code from the build guide (get_build_guide, get_build_guide_file). Only games you are a member of are visible. Follow a game from Demo to production with get_release_status (it says what to do next) and promote_release. Push every finished step to main as soon as npm run check passes, so Demo always shows the latest work; never hold finished work back to bundle it with something still in progress. Bundle TestFlight versions instead: each app build costs macOS minutes, a Demo build does not.';

    // Standardsidan är 15 verktyg; klienter som struntar i nextCursor ska se alla.
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        Tools\ListGamesTool::class,
        Tools\CreateGameTool::class,
        Tools\GetProvisioningStatusTool::class,
        Tools\RetryProvisioningTool::class,
        Tools\RequestLiveTool::class,
        Tools\GetReleaseStatusTool::class,
        Tools\PromoteReleaseTool::class,
        Tools\SetReleaseTargetsTool::class,
        Tools\ArchiveGameTool::class,
        Tools\GetBuildGuideTool::class,
        Tools\GetBuildGuideFileTool::class,
        Tools\ListFactsTool::class,
        Tools\SearchFactsTool::class,
        Tools\GetFactTool::class,
        Tools\GetGameTool::class,
        Tools\GetIntegrationGuideTool::class,
        Tools\SetAllowedOriginsTool::class,
        Tools\UpsertLeaderboardTypeTool::class,
        Tools\UpsertAchievementTool::class,
        Tools\UpsertContentPoolTool::class,
        Tools\UpsertRemoteConfigTool::class,
        Tools\UpsertGameEventTool::class,
    ];

    // Verktygslistan utan att starta servern. Invarianttesterna går igenom
    // den, så att varje nytt verktyg automatiskt omfattas.
    /** @return list<class-string<Tool>> */
    public static function toolClasses(): array
    {
        return (new \ReflectionClass(static::class))->getDefaultProperties()['tools'];
    }
}
