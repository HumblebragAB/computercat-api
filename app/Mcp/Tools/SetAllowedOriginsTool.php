<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use App\Rules\GameOrigin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

class SetAllowedOriginsTool extends GameTool
{
    protected string $name = 'set_allowed_origins';

    protected string $description = 'Replace the list of web origins allowed to call the API (CORS) for a game. Max 20. One invalid origin rejects the whole list and nothing changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'origins' => $schema->array()->items($schema->string())->description('Replaces the full list. https://host[:port], no path.')->required(),
        ];
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'origins' => ['present', 'array', 'max:20'],
            'origins.*' => ['string', new GameOrigin],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        // Normalisering och cache-rensning sker i Game-hookarna.
        $game->update(['allowed_origins' => array_values($validator->validated()['origins'])]);

        return $this->json(['saved' => true, 'allowed_origins' => $game->fresh()->allowed_origins]);
    }
}
