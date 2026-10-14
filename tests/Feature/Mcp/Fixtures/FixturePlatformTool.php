<?php

namespace Tests\Feature\Mcp\Fixtures;

use App\Mcp\Tools\PlatformTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

class FixturePlatformTool extends PlatformTool
{
    protected string $name = 'fixture_platform_tool';

    protected string $description = 'Test fixture.';

    public function schema(JsonSchema $schema): array
    {
        return ['mode' => $schema->string()];
    }

    protected function run(array $args, User $user): Response
    {
        return match ($args['mode'] ?? 'ok') {
            'invalid' => $this->validationFailed(['slug' => ['The slug is wrong.']]),
            'throw_validation' => throw ValidationException::withMessages(['slug' => 'The slug is wrong.']),
            'boom' => throw new \RuntimeException('internal detail'),
            default => $this->json(['user' => $user->id]),
        };
    }
}
