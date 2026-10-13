<?php

namespace App\Mcp;

use Laravel\Mcp\Server;

class ComputercatServer extends Server
{
    protected string $name = 'Computercat';

    protected string $version = '1.0.0';

    protected string $instructions = 'Configure Computercat games: leaderboards, achievements, content pools, remote config, events and allowed origins. Only games you are a member of are visible.';

    protected array $tools = [
        Tools\ListGamesTool::class,
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
    /** @return list<class-string<\Laravel\Mcp\Server\Tool>> */
    public static function toolClasses(): array
    {
        return (new \ReflectionClass(static::class))->getDefaultProperties()['tools'];
    }
}
