<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use App\Services\IntegrationGuide;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

class GetIntegrationGuideTool extends GameTool
{
    protected string $name = 'get_integration_guide';

    protected string $description = 'Get the integration manual (Markdown) for a game: base URL, authentication, and the API endpoints for the selected features. Contains no tokens; the app creates an anonymous user itself.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'features' => $schema->array()
                ->items($schema->string()->enum(array_keys(IntegrationGuide::FEATURES)))
                ->description('Optional; omitted = features this game has configured'),
        ];
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', Rule::in(array_keys(IntegrationGuide::FEATURES))],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $features = array_key_exists('features', $args)
            ? array_values(array_unique($args['features']))
            : IntegrationGuide::defaultFeatures($game);

        return Response::text(IntegrationGuide::manual($game, $features));
    }
}
