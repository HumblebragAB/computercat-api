<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Services\BuildGuide;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BuildGuideToolsTest extends McpTestCase
{
    public function test_staff_gets_the_manifest_without_any_game(): void
    {
        $result = $this->callTool($this->staffMember(), 'get_build_guide');

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame(BuildGuide::LATEST, $json['version']);
        $this->assertSame('16.4.0', $json['stack']['next']);
        $this->assertContains('npm audit --omit=dev --audit-level=high', array_column($json['required_checks'], 'command'));
        $this->assertSame(BuildGuide::versions(), $json['available_versions']);
        $this->assertSame(['ok'], McpAuditLog::where('tool', 'get_build_guide')->pluck('outcome')->all());
    }

    public function test_file_is_returned_exactly_with_its_sha256(): void
    {
        $result = $this->callTool($this->staffMember(), 'get_build_guide_file', ['path' => 'next.config.ts', 'version' => 'v1']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame(BuildGuide::file('v1', 'next.config.ts')['content'], $json['content']);
        $this->assertSame(hash('sha256', $json['content']), $json['sha256']);
        $this->assertSame('mcp', $json['delivery']);
    }

    public function test_large_file_points_to_download(): void
    {
        $json = json_decode($this->callTool($this->staffMember(), 'get_build_guide_file', ['path' => 'package-lock.json'])['text'], true);

        $this->assertSame('download', $json['delivery']);
        $this->assertNull($json['content']);
        $this->assertStringEndsWith('/api/v1/build-guide/'.BuildGuide::LATEST.'/files/package-lock.json', $json['download_url']);
    }

    public function test_unknown_path_and_version_are_errors(): void
    {
        $staff = $this->staffMember();

        $this->assertStringContainsString('No such file', $this->callTool($staff, 'get_build_guide_file', ['path' => '../../.env'])['text']);
        $this->assertStringContainsString('No such file', $this->callTool($staff, 'get_build_guide_file', ['path' => 'README.md'])['text']);
        $this->assertStringContainsString('Unknown build guide version', $this->callTool($staff, 'get_build_guide', ['version' => 'v9'])['text']);
        $this->assertFalse($this->callTool($staff, 'get_build_guide', ['version' => 'x'])['ok']);
    }

    // Claude Code lägger svar över 50 000 tecken i en fil (code.claude.com/docs/en/mcp).
    public function test_every_mcp_response_fits_below_50000_characters(): void
    {
        $staff = $this->staffMember();

        $this->assertLessThan(50000, mb_strlen($this->callTool($staff, 'get_build_guide')['text']));
        foreach (BuildGuide::manifest(BuildGuide::LATEST)['files'] as $entry) {
            $text = $this->callTool($staff, 'get_build_guide_file', ['path' => $entry['path']])['text'];
            $this->assertLessThan(50000, mb_strlen($text), $entry['path']);
        }
    }

    public function test_unpublished_version_is_unknown(): void
    {
        $root = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $root);
        try {
            File::copyDirectory("{$root}/v1", "{$root}/v99");
            $manifest = json_decode(file_get_contents("{$root}/v99/manifest.json"), true);
            $manifest['version'] = 'v99';
            file_put_contents("{$root}/v99/manifest.json", json_encode($manifest));
            config(['byggguide.path' => $root]);
            $staff = $this->staffMember();

            $guide = $this->callTool($staff, 'get_build_guide', ['version' => 'v99']);
            $file = $this->callTool($staff, 'get_build_guide_file', ['path' => 'package.json', 'version' => 'v99']);

            $this->assertFalse($guide['ok']);
            $this->assertStringContainsString('Unknown build guide version', $guide['text']);
            $this->assertFalse($file['ok']);
            $this->assertStringContainsString('Unknown build guide version', $file['text']);
            $this->assertStringNotContainsString('v99', json_encode($this->callTool($staff, 'get_build_guide')));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
