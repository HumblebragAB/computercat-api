<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Mcp\Tools\Concerns\ToolHelpers;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Bas för alla verktyg som tar ett spel: åtkomst, transaktion och
// revisionslogg sker här, så att ett nytt verktyg inte kan glömma dem.
abstract class GameTool extends Tool
{
    use ToolHelpers;

    // Körs i en transaktion om transactional() är sant. Ett felsvar (isError)
    // rullar tillbaka allt som run() hunnit skriva, likaså ett undantag.
    abstract protected function run(Game $game, array $args, User $user): Response;

    // Subklassens schema() ska börja med [...$this->gameSchema($schema), ...].
    protected function gameSchema(JsonSchema $schema): array
    {
        return ['game' => $schema->string()->description('Game slug')->required()];
    }

    // Verktyg som anropar externa tjänster (Slack) eller köar jobb svarar
    // false: inget HTTP-anrop får ske inne i en databastransaktion.
    protected function transactional(): bool
    {
        return true;
    }

    // Anropas när spelet inte hittas. Ett verktyg kan då svara om något som
    // finns utan spel (en provisionering som stannade innan spelet skapades).
    // Null ger samma "Game not found" som för icke-medlemmar.
    protected function whenGameMissing(User $user, string $slug, array $args): ?Response
    {
        return null;
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
            try {
                $fallback = $user && $user->isStaff() && $slug !== '' ? $this->whenGameMissing($user, $slug, $args) : null;
            } catch (\Throwable $e) {
                return $this->unexpected($user, null, $args, $e);
            }

            if ($fallback !== null) {
                $this->audit($user, null, $args, $fallback->isError() ? 'validation_error' : 'ok');

                return $fallback;
            }

            $this->audit($user, null, $args, 'denied');

            return $this->gameNotFound();
        }

        try {
            $response = $this->transactional()
                ? $this->runInTransaction($game, $args, $user)
                : $this->run($game, $args, $user);
        } catch (ValidationException $e) {
            $response = $this->validationFailed($e->errors());
        } catch (\Throwable $e) {
            return $this->unexpected($user, $game, $args, $e);
        }

        // Skrivs utanför transaktionen så att även misslyckade anrop loggas.
        $this->audit($user, $game, $args, $response->isError() ? 'validation_error' : 'ok');

        return $response;
    }

    protected function gameNotFound(): Response
    {
        return Response::error('Game not found.');
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
}
