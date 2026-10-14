<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Byggguidens manifest för ett nytt spelrepo (spec 2c, "Byggguiden"). Tar inget spel.
class GetBuildGuideTool extends PlatformTool
{
    protected string $name = 'get_build_guide';

    protected string $description = 'Get the Computercat build guide for a game repo: the stack with exact versions, the install command, the required checks, the ordered steps, and every file to write (path, sha256, bytes, delivery). Fetch files with delivery "mcp" with get_build_guide_file and files with delivery "download" with curl from download_url. Write every file exactly and verify its sha256. Contains no secrets.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'version' => $schema->string()->description('Optional, e.g. "v1". Omitted = the latest version.'),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, ['version' => ['sometimes', 'string', 'regex:'.BuildGuide::VERSION_PATTERN]]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            $guide = BuildGuide::publicManifest($args['version'] ?? BuildGuide::LATEST);
        } catch (BuildGuideNotFound) {
            return Response::error('Unknown build guide version. Available: '.implode(', ', BuildGuide::versions()).'.');
        }

        return $this->json($guide + ['available_versions' => BuildGuide::versions()]);
    }
}
