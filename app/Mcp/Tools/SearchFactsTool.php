<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BuildGuide;
use App\Services\FactBank;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

// Sökning i faktabanken (spec delprojekt 3). Bara läsning.
class SearchFactsTool extends PlatformTool
{
    protected string $name = 'search_facts';

    protected string $description = 'Search the Computercat fact bank for how we build games. Ask before choosing a library or adding a dependency, before writing tests, when a build or check fails, and before promote_release or request_live. Search in Swedish or English; the articles are in Swedish. Returns up to 10 articles with id, title and summary; read one with get_fact. Pass build_guide with the version in the game\'s CLAUDE.md. Articles override your own judgement; if nothing covers a big decision, ask a human.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to search for, e.g. "nytt beroende" or "anti-cheat topplista".')->required(),
            'topic' => $schema->string()->enum(FactBank::TOPICS)->description('Optional topic filter.'),
            'build_guide' => $schema->string()->description('Optional, the game\'s build guide version, e.g. "v1".'),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'query' => ['required', 'string', 'min:2', 'max:200', function ($attribute, $value, $fail) {
                if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    $fail('The query must be valid UTF-8.');
                }
            }],
            'topic' => ['sometimes', 'nullable', 'string', Rule::in(FactBank::TOPICS)],
            'build_guide' => ['sometimes', 'nullable', 'string', 'regex:'.BuildGuide::VERSION_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $results = app(FactBank::class)->search($args['query'], $args['topic'] ?? null, $args['build_guide'] ?? null);
        $out = [
            'query' => $args['query'],
            'results' => array_map(fn (array $f) => [
                'id' => $f['id'], 'title' => $f['title'], 'topic' => $f['topic'],
                'summary' => $f['summary'], 'build_guide' => $f['build_guide'], 'score' => $f['score'],
            ], $results),
        ];
        if ($results === []) {
            $out['next'] = 'No matches. Run list_facts to see the topics and titles.';
        }

        return $this->json($out);
    }
}
