<?php

namespace App\Mcp;

use Laravel\Mcp\Server;

class ComputercatServer extends Server
{
    protected string $name = 'Computercat';

    protected string $version = '1.0.0';

    protected string $instructions = 'Configure Computercat games: leaderboards, achievements, content pools, remote config, events and allowed origins. Only games you are a member of are visible.';

    protected array $tools = [
        Tools\PingTool::class,
    ];
}
