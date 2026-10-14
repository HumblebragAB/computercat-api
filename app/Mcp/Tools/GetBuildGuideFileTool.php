<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// En fil ur byggguiden. Stora filer (package-lock.json) skickas inte här:
// svaret hålls under Claude Codes 50 000 tecken och pekar på REST-vägen.
class GetBuildGuideFileTool extends PlatformTool
{
    protected string $name = 'get_build_guide_file';

    protected string $description = 'Get one file from the Computercat build guide: path, sha256, bytes and the exact content to write at that path in the game repo. Files larger than 30000 bytes return content null and a download_url (fetch with curl). Use the paths listed by get_build_guide.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('A path from get_build_guide, e.g. "src/lib/computercat/index.ts".')->required(),
            'version' => $schema->string()->description('Optional, e.g. "v1". Omitted = the latest version.'),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'path' => ['required', 'string', 'max:200'],
            'version' => ['sometimes', 'string', 'regex:'.BuildGuide::VERSION_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $version = $args['version'] ?? BuildGuide::LATEST;
        try {
            $file = BuildGuide::file($version, $args['path']);
        } catch (BuildGuideNotFound) {
            return Response::error("No such file in build guide {$version}. Call get_build_guide for the file list.");
        }

        $inline = $file['bytes'] <= BuildGuide::INLINE_MAX_BYTES;

        return $this->json([
            'path' => $file['path'],
            'sha256' => $file['sha256'],
            'bytes' => $file['bytes'],
            'delivery' => $inline ? 'mcp' : 'download',
            'download_url' => $inline ? null : BuildGuide::downloadUrl($version, $file['path']),
            'content' => $inline ? $file['content'] : null,
        ]);
    }
}
