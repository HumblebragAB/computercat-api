<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Rules\GameOrigin;
use App\Support\SlackAlert;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Ber Erik om Live-gång. Ändrar ingenting; Live görs för hand.
// Tills etapp 1b öppnar webbproduktion i promote_release är det här enda vägen till Erik.
class RequestLiveTool extends GameTool
{
    protected string $name = 'request_live';

    protected string $description = 'Ask Erik to take a finished Demo game live, optionally with a desired production domain. Sends a notification only; nothing is changed. Only the game owner. Production cannot be requested with promote_release yet; use get_release_status to follow the flow.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'domain' => $schema->string()->description('Optional desired domain, e.g. kattsvep.se (no scheme, no path).'),
        ];
    }

    // Slack-anropet får inte ske inne i en databastransaktion.
    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! GameAccess::isOwner($user, $game)) {
            return Response::error('Only the game owner can request Live.');
        }

        // Tom sträng hoppar över closure-regeln i Laravel; behandla den som utelämnad.
        if (array_key_exists('domain', $args) && is_string($args['domain']) && trim($args['domain']) === '') {
            $args['domain'] = null;
        }

        $validator = Validator::make($args, ['domain' => ['bail', 'nullable', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail) {
            // isValid tål avslutande snedstreck och port; en domän får ingetdera.
            if (preg_match('/[\/:\s]/', $value) === 1 || ! GameOrigin::isValid('https://'.$value)) {
                $fail('The domain must be a plain host name such as kattsvep.se.');
            }
        }]]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }
        $domain = $validator->validated()['domain'] ?? null;

        $run = ProvisioningRun::provision()->where('game_id', $game->id)->where('status', ProvisioningRun::SUCCEEDED)->orderByDesc('id')->first();
        if ($run === null) {
            return Response::error('Only games with a finished Demo can request Live.');
        }

        $text = 'Live-förfrågan för `'.SlackAlert::escape($game->slug).'` ('.SlackAlert::escape($game->name).') från '.SlackAlert::escape((string) ($user->email ?? $user->name))
            .". Demo: {$run->demo_url}. Önskad domän: ".($domain === null ? 'ingen angiven' : SlackAlert::escape($domain)).'.';

        return match (app(SlackAlert::class)->send("request_live:{$game->slug}", $text)) {
            SlackAlert::SENT => $this->json(['requested' => true, 'message' => 'Erik has been notified. Nothing was changed. Production cannot be requested with promote_release yet in this version; use get_release_status to follow the flow.']),
            SlackAlert::DUPLICATE => $this->json(['requested' => true, 'message' => 'Erik was already notified about this today. Nothing was changed. Production cannot be requested with promote_release yet in this version; use get_release_status to follow the flow.']),
            default => Response::error('Could not notify Erik. Nothing was changed; try again later.'),
        };
    }
}
