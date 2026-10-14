<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class ComputercatServer extends Server
{
    protected string $name = 'Computercat';

    protected string $version = '1.0.0';

    protected string $instructions = 'Configure Computercat games: leaderboards, achievements, content pools, remote config, events and allowed origins. Create new Demo games with create_game and follow them with get_provisioning_status. A new game repo gets its base code from the build guide (get_build_guide, get_build_guide_file). Only games you are a member of are visible.';

    // Standardsidan är 15 verktyg; klienter som struntar i nextCursor ska se alla.
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        Tools\ListGamesTool::class,
        Tools\CreateGameTool::class,
        Tools\GetProvisioningStatusTool::class,
        Tools\RetryProvisioningTool::class,
        Tools\RequestLiveTool::class,
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
