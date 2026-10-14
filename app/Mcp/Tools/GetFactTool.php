<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\FactBank;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// En artikel ur faktabanken (spec delprojekt 3). id slås upp i indexet, aldrig som sökväg.
class GetFactTool extends PlatformTool
{
    protected string $name = 'get_fact';

    protected string $description = 'Read one article from the Computercat fact bank (rule, why, how to do it, sources). Get ids from search_facts or list_facts. Follow the rule; if it does not fit the game, ask a human instead of deviating.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Article id, e.g. "stack-nytt-beroende".')->required(),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'id' => ['required', 'string', 'max:80', 'regex:'.FactBank::ID_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $fact = app(FactBank::class)->find($args['id']);
        if ($fact === null) {
            return Response::error('No such article. Use search_facts or list_facts to find ids.');
        }

        return $this->json($fact);
    }
}
