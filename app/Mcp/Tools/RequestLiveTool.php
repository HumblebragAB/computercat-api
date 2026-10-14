<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

// Ersatt av promote_release (spec Del 1). Verktyget finns kvar som tunn väg in så att
// inget befintligt flöde slutar fungera, men ändrar och skickar ingenting.
class RequestLiveTool extends GameTool
{
    protected string $name = 'request_live';

    protected string $description = 'Replaced by promote_release. Does nothing except point you to promote_release(target: web, stage: web_production) and get_release_status. Kept so old instructions still get an answer.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'domain' => $schema->string()->description('Ignored. Erik sets the public address as a one-time setup step.'),
        ];
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $message = 'request_live is replaced. Call get_release_status to see what to do next, and promote_release(target: web, stage: web_production, commit_sha: <commit with a successful Demo build>) to ask for a production release. The first production release per game is approved by Erik; nothing is changed by this call.';

        $domain = $args['domain'] ?? null;
        if (is_string($domain) && trim($domain) !== '') {
            // Aldrig tyst: en angiven domän sparas inte längre här.
            $message .= ' The domain '.mb_substr(trim($domain), 0, 100).' was not recorded; Erik sets the public address as a setup step.';
        }

        return $this->json(['requested' => false, 'message' => $message]);
    }
}
