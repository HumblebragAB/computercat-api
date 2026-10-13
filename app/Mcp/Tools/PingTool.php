<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Spike-verktyg för att verifiera OAuth-rundturen. Tas bort i Task 7.
class PingTool extends Tool
{
    // Utan namn blir verktyget "ping-tool" (kebab av klassnamnet).
    protected string $name = 'ping';

    protected string $description = 'Returns pong and the authenticated user id.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text('pong '.$request->user()?->id);
    }
}
