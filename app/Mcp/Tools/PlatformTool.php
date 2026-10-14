<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ToolHelpers;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Bas för verktyg som inte tar ett spel (list_games, create_game): samma
// staff-kontroll, felhantering och revisionslogg som GameTool, utan spel.
// Ingen transaktion: verktygen sköter sina egna skrivningar.
abstract class PlatformTool extends Tool
{
    use ToolHelpers;

    abstract protected function run(array $args, User $user): Response;

    public function handle(Request $request): Response
    {
        $user = User::find($request->user()?->getKey());
        $args = $request->all();

        if (! $user || ! $user->isStaff()) {
            $this->audit($user, null, $args, 'denied');

            return Response::error('Not allowed.');
        }

        try {
            $response = $this->run($args, $user);
        } catch (ValidationException $e) {
            $response = $this->validationFailed($e->errors());
        } catch (\Throwable $e) {
            return $this->unexpected($user, null, $args, $e);
        }

        $this->audit($user, null, $args, $response->isError() ? 'validation_error' : 'ok');

        return $response;
    }
}
