<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

// Byggguiden (spec 2c, "Byggguiden"). Två uttryck för samma sanning får aldrig
// glida isär: manifestet och filerna på disk, och manifestets stack och package.json.
class BuildGuideTest extends TestCase
{
    private ?string $tempRoot = null;

    protected function tearDown(): void
    {
        // Bara katalogen som testet självt skapade.
        if ($this->tempRoot !== null) {
            File::deleteDirectory($this->tempRoot);
        }
        parent::tearDown();
    }

    /** Kopia av guiden i en temporär katalog, för tester som måste ändra filer. */
    private function tempGuide(): string
    {
        $this->tempRoot = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $this->tempRoot);
        config(['byggguide.path' => $this->tempRoot]);

        return $this->tempRoot;
    }

    public function test_latest_version_exists(): void
    {
        $this->assertContains(BuildGuide::LATEST, BuildGuide::versions());
        $this->assertSame('v1', BuildGuide::manifest()['version']);
    }

    public function test_manifest_has_the_rules(): void
    {
        $m = BuildGuide::manifest('v1');

        $this->assertSame('22.x', $m['node']);
        $this->assertSame(['vite' => '7.3.7'], $m['overrides']);
        $this->assertSame('npm ci --ignore-scripts', $m['commands']['install']);
        $this->assertSame(
            ['npm run check', 'npm audit --omit=dev --audit-level=high', 'test -f out/index.html'],
            array_column($m['required_checks'], 'command'),
        );
        $this->assertNotEmpty($m['steps']);
        foreach ($m['stack'] as $package => $version) {
            // Exakt låsta versioner: inga intervall.
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\z/', $version, $package);
        }
    }

    public function test_stack_matches_package_json(): void
    {
        $package = json_decode(BuildGuide::file('v1', 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
        $m = BuildGuide::manifest('v1');

        $this->assertSame($package['dependencies'] + $package['devDependencies'], $m['stack']);
        $this->assertSame($package['overrides'], $m['overrides']);
        $this->assertSame($package['engines']['node'], $m['node']);
    }

    public function test_every_file_matches_its_sha256_and_nothing_is_unlisted(): void
    {
        foreach (BuildGuide::versions() as $version) {
            $root = BuildGuide::root($version);
            $m = BuildGuide::manifest($version);
            $listed = [];

            foreach ($m['files'] as $entry) {
                $this->assertFalse(str_contains($entry['path'], '..') || str_starts_with($entry['path'], '/'), $entry['path']);
                $this->assertStringStartsWith('files/', $entry['source']);
                $this->assertFileExists("{$root}/{$entry['source']}");
                $this->assertSame($entry['sha256'], hash_file('sha256', "{$root}/{$entry['source']}"), $entry['path']);
                $this->assertSame($entry['bytes'], filesize("{$root}/{$entry['source']}"), $entry['path']);
                $listed[] = $entry['source'];
            }

            $readme = $m['server_templates']['readme'];
            $this->assertSame($readme['sha256'], hash_file('sha256', "{$root}/{$readme['source']}"));
            $listed[] = $readme['source'];

            $onDisk = [];
            foreach ((new Finder)->files()->in($root)->ignoreDotFiles(false)->notName('manifest.json') as $file) {
                $onDisk[] = str_replace('\\', '/', $file->getRelativePathname());
            }
            sort($listed);
            sort($onDisk);
            $this->assertSame($listed, $onDisk, "{$version}: filer på disk och i manifestet skiljer sig");
            $this->assertSame(count($m['files']), count(array_unique(array_column($m['files'], 'path'))), 'dubblerad path');
        }
    }

    public function test_required_files_are_in_the_guide_and_readme_is_not(): void
    {
        $paths = array_column(BuildGuide::manifest('v1')['files'], 'path');

        foreach (['package.json', 'package-lock.json', '.gitignore', '.env.example', 'next.config.ts', 'tsconfig.json', 'eslint.config.mjs', 'vitest.config.ts', 'postcss.config.mjs', 'scripts/check-export.mjs', 'CLAUDE.md', 'test/docs.test.ts', 'test/fakeFetch.ts', 'src/lib/computercat/index.ts', 'src/lib/computercat/auth.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
        // README skrivs av create_game ur mallen; spelets Claude skriver aldrig över den.
        $this->assertNotContains('README.md', $paths);
    }

    public function test_claude_md_tells_the_game_claude_to_use_the_guide(): void
    {
        $md = BuildGuide::claudeMd('v1');

        foreach (['Byggguide: **v1**', '`get_build_guide`', '`get_build_guide_file`', 'sha256sum', 'npm run check', 'npm audit --omit=dev --audit-level=high', 'Bygg aldrig en egen backend', 'Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertStringNotContainsString('HumblebragAB/spelmall', $md);
    }

    public function test_readme_inserts_the_name_safely_and_keeps_one_pair_of_markers(): void
    {
        $readme = BuildGuide::readme('v1', "Katt<!-- x -->\nsvep");

        $this->assertStringStartsWith("# Katt&lt;!-- x --&gt; svep\n", $readme);
        $this->assertSame(1, substr_count($readme, '<!-- computercat:idea:start -->'));
        $this->assertSame(1, substr_count($readme, '<!-- computercat:idea:end -->'));
        $this->assertStringNotContainsString('{{name}}', $readme);
    }

    public function test_public_manifest_hides_sources_and_marks_large_files_for_download(): void
    {
        $public = BuildGuide::publicManifest('v1');
        $files = collect($public['files'])->keyBy('path');

        $this->assertSame('download', $files['package-lock.json']['delivery']);
        $this->assertSame('mcp', $files['package.json']['delivery']);
        $this->assertStringStartsWith('https://', $files['package-lock.json']['download_url']);
        $this->assertStringEndsWith('/api/v1/build-guide/v1/files/package-lock.json', $files['package-lock.json']['download_url']);
        $this->assertNull($files['.gitignore']['download_url']);
        $this->assertArrayNotHasKey('source', $files['package.json']);
        $this->assertArrayNotHasKey('server_templates', $public);
    }

    public function test_paths_outside_the_manifest_and_unknown_versions_are_refused(): void
    {
        foreach (['../../.env', '/etc/passwd', 'README.md', 'files/package.json'] as $path) {
            try {
                BuildGuide::file('v1', $path);
                $this->fail("Släpptes igenom: {$path}");
            } catch (BuildGuideNotFound) {
                // väntat
            }
        }

        foreach (['v0', 'v9', '../v1', 'v1/..', ''] as $version) {
            try {
                BuildGuide::manifest($version);
                $this->fail("Släpptes igenom: {$version}");
            } catch (BuildGuideNotFound) {
                // väntat
            }
        }
    }

    public function test_tampered_file_fails_loudly_instead_of_being_served(): void
    {
        $root = $this->tempGuide();
        file_put_contents("{$root}/v1/files/next.config.ts", "export default {};\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stämmer inte med manifestets sha256');
        BuildGuide::file('v1', 'next.config.ts');
    }

    // En publicerad version ändras aldrig (spec 2c). Ändras manifest.json för
    // en version i FROZEN fäller testet; en ändring ska bli en ny version.
    public function test_published_versions_are_frozen(): void
    {
        foreach (BuildGuide::versions() as $version) {
            $this->assertArrayHasKey($version, BuildGuide::FROZEN, "{$version} saknas i BuildGuide::FROZEN");
            $this->assertNotSame('', BuildGuide::FROZEN[$version], "{$version} är inte låst i BuildGuide::FROZEN");
            $this->assertSame(BuildGuide::FROZEN[$version], hash_file('sha256', BuildGuide::root($version).'/manifest.json'), "{$version} har ändrats efter publicering");
        }
    }

    public function test_hash_command_refuses_a_frozen_version_and_hashes_an_unpublished_one(): void
    {
        $this->artisan('computercat:byggguide:hash v1')->expectsOutputToContain('är publicerad')->assertExitCode(1);

        $root = $this->tempGuide();
        File::copyDirectory("{$root}/v1", "{$root}/v2");
        $manifest = json_decode(file_get_contents("{$root}/v2/manifest.json"), true);
        $manifest['version'] = 'v2';
        $manifest['files'] = [];
        file_put_contents("{$root}/v2/manifest.json", json_encode($manifest));
        file_put_contents("{$root}/v2/files/next.config.ts", "export default {};\n");

        $this->artisan('computercat:byggguide:hash v2')->assertExitCode(0);

        $this->assertSame(hash('sha256', "export default {};\n"), BuildGuide::file('v2', 'next.config.ts')['sha256']);
        $this->assertContains('.gitignore', array_column(BuildGuide::manifest('v2')['files'], 'path'));
    }

    // En symbolisk länk i guiden kan peka var som helst på servern; den
    // serveras aldrig, inte ens när innehållet råkar stämma med sha256.
    public function test_symlinked_file_is_refused_even_with_matching_sha256(): void
    {
        $root = $this->tempGuide();
        $target = "{$root}/utanfor-next.config.ts";
        copy("{$root}/v1/files/next.config.ts", $target);
        unlink("{$root}/v1/files/next.config.ts");
        symlink($target, "{$root}/v1/files/next.config.ts");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolisk länk');
        BuildGuide::file('v1', 'next.config.ts');
    }

    public function test_hash_command_refuses_symlinks(): void
    {
        $root = $this->tempGuide();
        File::copyDirectory("{$root}/v1", "{$root}/v2");
        $manifest = json_decode(file_get_contents("{$root}/v2/manifest.json"), true);
        $manifest['version'] = 'v2';
        file_put_contents("{$root}/v2/manifest.json", json_encode($manifest));
        symlink("{$root}/v2/files/package.json", "{$root}/v2/files/lank.json");

        $this->artisan('computercat:byggguide:hash v2')->expectsOutputToContain('symbolisk länk')->assertExitCode(1);
    }

    public function test_download_url_requires_an_https_app_url(): void
    {
        config(['app.url' => 'http://computercat.test']);
        $this->assertSame('https://computercat.test/api/v1/build-guide/v1/files/package-lock.json', BuildGuide::downloadUrl('v1', 'package-lock.json'));

        foreach (['', 'computercat.test', 'ftp://computercat.test', 'https://'] as $url) {
            config(['app.url' => $url]);
            try {
                BuildGuide::downloadUrl('v1', 'package-lock.json');
                $this->fail("Släpptes igenom: '{$url}'");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('APP_URL', $e->getMessage());
            }
        }
    }

    // Forges nginx nekar sökvägar med /. (kommentaren i publicManifest): en
    // dotfil måste alltid gå inline via MCP och får därför aldrig bli för stor.
    public function test_dotfiles_are_always_delivered_inline(): void
    {
        foreach (BuildGuide::versions() as $version) {
            foreach (BuildGuide::publicManifest($version)['files'] as $file) {
                $dotted = collect(explode('/', $file['path']))->contains(fn (string $segment) => str_starts_with($segment, '.'));
                if (! $dotted) {
                    continue;
                }
                $this->assertLessThanOrEqual(BuildGuide::INLINE_MAX_BYTES, $file['bytes'], $file['path']);
                $this->assertSame('mcp', $file['delivery'], $file['path']);
                $this->assertNull($file['download_url'], $file['path']);
            }
        }
    }

    // Installationsskript körs aldrig: varken i verify, av spelets Claude eller
    // i Demo-bygget. Varje installationskommando i guidens texter har flaggan.
    public function test_every_install_command_in_the_guide_ignores_scripts(): void
    {
        $texts = [
            'manifest' => json_encode(BuildGuide::manifest('v1'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'CLAUDE.md' => BuildGuide::claudeMd('v1'),
            'README' => BuildGuide::readme('v1', 'Spel'),
        ];

        foreach ($texts as $name => $text) {
            $this->assertStringContainsString('npm ci --ignore-scripts', $text, $name);
            $this->assertDoesNotMatchRegularExpression('/npm ci(?! --ignore-scripts)/', $text, $name);
        }
    }
}
