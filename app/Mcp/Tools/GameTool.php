<?php

namespace App\Mcp\Tools;

use App\Mcp\AuditLogger;
use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Bas för alla verktyg som tar ett spel: åtkomst, transaktion och
// revisionslogg sker här, så att ett nytt verktyg inte kan glömma dem.
abstract class GameTool extends Tool
{
    // Körs i en transaktion. Ett felsvar (isError) rullar tillbaka allt som
    // run() hunnit skriva, likaså ett undantag.
    abstract protected function run(Game $game, array $args, User $user): Response;

    // Subklassens schema() ska börja med [...$this->gameSchema($schema), ...].
    protected function gameSchema(JsonSchema $schema): array
    {
        return ['game' => $schema->string()->description('Game slug')->required()];
    }

    public function handle(Request $request): Response
    {
        $user = User::find($request->user()?->getKey());
        $args = $request->all();
        $slug = is_string($args['game'] ?? null) ? $args['game'] : '';
        // Okänt spel och spel man inte är medlem i ger samma svar, så att
        // verktyget inte avslöjar vilka spel som finns.
        $game = $user && $slug !== '' ? GameAccess::find($user, $slug) : null;

        if (! $game) {
            $this->audit($user, null, $args, 'denied');

            return $this->gameNotFound();
        }

        try {
            $response = $this->runInTransaction($game, $args, $user);
        } catch (ValidationException $e) {
            $response = $this->validationFailed($e->errors());
        } catch (\Throwable $e) {
            $ref = (string) Str::uuid();
            Log::error('MCP tool failed', ['ref' => $ref, 'tool' => $this->name(), 'user_id' => $user->id, 'game' => $game->slug, 'args' => AuditLogger::summarize($args), 'exception' => $e]);
            $this->audit($user, $game, $args, 'error');

            // Undantagets text kan innehålla interna detaljer; klienten får bara referensen.
            return Response::error("Unexpected error (ref {$ref}).");
        }

        // Skrivs utanför transaktionen så att även misslyckade anrop loggas.
        $this->audit($user, $game, $args, $response->isError() ? 'validation_error' : 'ok');

        return $response;
    }

    protected function gameNotFound(): Response
    {
        return Response::error('Game not found.');
    }

    /** @param array<string, string|array<int, string>> $errors */
    protected function validationFailed(array $errors): Response
    {
        return Response::error("Validation failed:\n".collect($errors)->map(fn ($m, $f) => "- {$f}: ".implode(' ', (array) $m))->implode("\n"));
    }

    protected function json(array $data): Response
    {
        return Response::text(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function runInTransaction(Game $game, array $args, User $user): Response
    {
        DB::beginTransaction();

        try {
            $response = $this->run($game, $args, $user);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $response->isError() ? DB::rollBack() : DB::commit();

        return $response;
    }

    // En trasig loggning får inte ändra svaret: ändringen är redan gjord
    // (eller nekad). Felet rapporteras i stället.
    private function audit(?User $user, ?Game $game, array $args, string $outcome): void
    {
        try {
            AuditLogger::record($user, $game, $this->name(), $args, $outcome);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
