<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BuildGuide;
use App\Services\FactBank;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

// Faktabankens innehållsförteckning (spec delprojekt 3). Bara läsning.
class ListFactsTool extends PlatformTool
{
    protected string $name = 'list_facts';

    protected string $description = 'List the articles in the Computercat fact bank: how we build games (stack choices, tests, security checks before Demo, best practice, process from idea to Live). Use it to see what exists; then read an article with get_fact. Pass build_guide with the version in the game\'s CLAUDE.md (e.g. "v1") to get only articles that apply to that game. Articles override your own judgement; if nothing covers a big decision, ask a human.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->enum(FactBank::TOPICS)->description('Optional topic filter.'),
            'build_guide' => $schema->string()->description('Optional, the game\'s build guide version, e.g. "v1".'),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'topic' => ['sometimes', 'nullable', 'string', Rule::in(FactBank::TOPICS)],
            'build_guide' => ['sometimes', 'nullable', 'string', 'regex:'.BuildGuide::VERSION_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $facts = app(FactBank::class)->all($args['topic'] ?? null, $args['build_guide'] ?? null);

        return $this->json([
            'topics' => FactBank::TOPICS,
            'facts' => array_map(fn (array $f) => [
                'id' => $f['id'], 'title' => $f['title'], 'topic' => $f['topic'],
                'summary' => $f['summary'], 'tags' => $f['tags'], 'build_guide' => $f['build_guide'],
            ], $facts),
        ]);
    }
}
