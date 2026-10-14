<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\AuditLogger;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Throwable;

// Delas av GameTool och PlatformTool, så att svar, fel och revisionslogg ser
// likadana ut oavsett om verktyget tar ett spel.
trait ToolHelpers
{
    /** @param array<string, string|array<int, string>> $errors */
    protected function validationFailed(array $errors): Response
    {
        return Response::error("Validation failed:\n".collect($errors)->map(fn ($m, $f) => "- {$f}: ".implode(' ', (array) $m))->implode("\n"));
    }

    protected function json(array $data): Response
    {
        return Response::text(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // Undantagets text kan innehålla interna detaljer; klienten får bara referensen.
    protected function unexpected(?User $user, ?Game $game, array $args, Throwable $e): Response
    {
        $ref = (string) Str::uuid();
        Log::error('MCP tool failed', ['ref' => $ref, 'tool' => $this->name(), 'user_id' => $user?->id, 'game' => $game?->slug, 'args' => AuditLogger::summarize($args), 'exception' => $e]);
        $this->audit($user, $game, $args, 'error');

        return Response::error("Unexpected error (ref {$ref}).");
    }

    // En trasig loggning får inte ändra svaret: ändringen är redan gjord
    // (eller nekad). Felet rapporteras i stället.
    protected function audit(?User $user, ?Game $game, array $args, string $outcome): void
    {
        try {
            AuditLogger::record($user, $game, $this->name(), $args, $outcome);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
