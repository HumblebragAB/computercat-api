<?php

namespace Tests\Feature\Mcp\Fixtures;

use App\Mcp\ComputercatServer;

class FixtureServer extends ComputercatServer
{
    protected array $tools = [FixtureGameTool::class];
}
