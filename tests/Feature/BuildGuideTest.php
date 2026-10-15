<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

// Byggguiden (spec 2c, "Byggguiden"; delprojekt 3 del 3, "Versioner"). Två uttryck för samma
// sanning får aldrig glida isär: manifestet och filerna på disk, och manifestets stack och
// package.json. Versionsspecifika tester körs för varje låst version (BuildGuide::FROZEN).
class BuildGuideTest extends TestCase
{
    // Det som skiljer de låsta versionerna åt. En version läggs till här i samma
    // commit som den låses (test_every_published_version_has_expectations vaktar).
    private const EXPECTED = [
        'v1' => ['overrides' => ['vite' => '7.3.7']],
        'v2' => ['overrides' => ['vite' => '7.3.7']],
    ];

    private ?string $tempRoot = null;

    /** @return array<string, array{string}> */
    public static function publishedVersions(): array
    {
        $versions = [];
        foreach (BuildGuide::FROZEN as $version => $sha) {
            if ($sha !== '') {
                $versions[$version] = [$version];
            }
        }

        return $versions;
    }

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

    /** En låst version kopierad till en olåst, i en temporär guide. */
    private function unpublishedCopy(string $version, string $from = 'v1'): string
    {
        $root = $this->tempGuide();
        File::copyDirectory("{$root}/{$from}", "{$root}/{$version}");
        $manifest = json_decode(file_get_contents("{$root}/{$version}/manifest.json"), true);
        $manifest['version'] = $version;
        file_put_contents("{$root}/{$version}/manifest.json", json_encode($manifest));

        return $root;
    }

    public function test_latest_version_is_published(): void
    {
        $this->assertContains(BuildGuide::LATEST, BuildGuide::versions());
        $this->assertTrue(BuildGuide::isPublished(BuildGuide::LATEST));
        $this->assertSame(BuildGuide::LATEST, BuildGuide::manifest()['version']);
    }

    public function test_every_published_version_has_expectations(): void
    {
        $this->assertSame(array_keys(self::publishedVersions()), array_keys(self::EXPECTED));
        $this->assertSame(array_keys(self::publishedVersions()), BuildGuide::versions());
    }

    #[DataProvider('publishedVersions')]
    public function test_manifest_has_the_rules(string $version): void
    {
        $m = BuildGuide::manifest($version);

        $this->assertSame($version, $m['version']);
        $this->assertSame('22.x', $m['node']);
        $this->assertSame(self::EXPECTED[$version]['overrides'], $m['overrides']);
        $this->assertSame('npm ci --ignore-scripts', $m['commands']['install']);
        $this->assertSame(
            ['npm run check', 'npm audit --omit=dev --audit-level=high', 'test -f out/index.html'],
            array_column($m['required_checks'], 'command'),
        );
        $this->assertStringContainsString("Grundkod från byggguide {$version}", implode("\n", $m['steps']));
        foreach ($m['stack'] as $package => $exact) {
            // Exakt låsta versioner: inga intervall.
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\z/', $exact, $package);
        }
    }

    #[DataProvider('publishedVersions')]
    public function test_stack_matches_package_json(string $version): void
    {
        $package = json_decode(BuildGuide::file($version, 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
        $m = BuildGuide::manifest($version);

        $this->assertSame($package['dependencies'] + $package['devDependencies'], $m['stack']);
        $this->assertSame($package['overrides'], $m['overrides']);
        $this->assertSame($package['engines']['node'], $m['node']);
    }

    // Alla kataloger på disk, även en olåst version under arbete: manifestet och filerna stämmer alltid.
    public function test_every_file_matches_its_sha256_and_nothing_is_unlisted(): void
    {
        $dirs = glob(resource_path('byggguide').'/v*', GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($dirs);

        foreach ($dirs as $root) {
            $version = basename($root);
            $m = BuildGuide::manifest($version);
            $listed = [];

            foreach ($m['files'] as $entry) {
                $this->assertFalse(str_contains($entry['path'], '..') || str_starts_with($entry['path'], '/'), $entry['path']);
                $this->assertStringStartsWith('files/', $entry['source']);
                $this->assertFileExists("{$root}/{$entry['source']}");
                $this->assertSame($entry['sha256'], hash_file('sha256', "{$root}/{$entry['source']}"), "{$version}: {$entry['path']}");
                $this->assertSame($entry['bytes'], filesize("{$root}/{$entry['source']}"), "{$version}: {$entry['path']}");
                $listed[] = $entry['source'];
            }

            $readme = $m['server_templates']['readme'];
            $this->assertSame($readme['sha256'], hash_file('sha256', "{$root}/{$readme['source']}"), "{$version}: README-mallen");
            $listed[] = $readme['source'];

            $onDisk = [];
            foreach ((new Finder)->files()->in($root)->ignoreDotFiles(false)->notName('manifest.json') as $file) {
                $onDisk[] = str_replace('\\', '/', $file->getRelativePathname());
            }
            sort($listed);
            sort($onDisk);
            $this->assertSame($listed, $onDisk, "{$version}: filer på disk och i manifestet skiljer sig");
            $this->assertSame(count($m['files']), count(array_unique(array_column($m['files'], 'path'))), "{$version}: dubblerad path");
        }
    }

    #[DataProvider('publishedVersions')]
    public function test_required_files_are_in_the_guide_and_readme_is_not(string $version): void
    {
        $paths = array_column(BuildGuide::manifest($version)['files'], 'path');

        foreach (['package.json', 'package-lock.json', '.gitignore', '.env.example', 'next.config.ts', 'tsconfig.json', 'eslint.config.mjs', 'vitest.config.ts', 'postcss.config.mjs', 'scripts/check-export.mjs', 'CLAUDE.md', 'test/docs.test.ts', 'test/fakeFetch.ts', 'src/lib/computercat/index.ts', 'src/lib/computercat/auth.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
        // README skrivs av create_game ur mallen; spelets Claude skriver aldrig över den.
        $this->assertNotContains('README.md', $paths);
    }

    #[DataProvider('publishedVersions')]
    public function test_claude_md_tells_the_game_claude_to_use_the_guide(string $version): void
    {
        $md = BuildGuide::claudeMd($version);

        foreach (["Byggguide: **{$version}**", "{\"version\": \"{$version}\"}", "`Grundkod från byggguide {$version}`", '`get_build_guide`', '`get_build_guide_file`', 'sha256sum', 'npm run check', 'npm audit --omit=dev --audit-level=high', 'Bygg aldrig en egen backend', 'Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertStringNotContainsString('HumblebragAB/spelmall', $md);
    }

    #[DataProvider('publishedVersions')]
    public function test_readme_inserts_the_name_safely_and_keeps_one_pair_of_markers(string $version): void
    {
        $readme = BuildGuide::readme($version, "Katt<!-- x -->\nsvep");

        $this->assertStringStartsWith("# Katt&lt;!-- x --&gt; svep\n", $readme);
        $this->assertStringContainsString("byggguide {$version}", $readme);
        $this->assertSame(1, substr_count($readme, '<!-- computercat:idea:start -->'));
        $this->assertSame(1, substr_count($readme, '<!-- computercat:idea:end -->'));
        $this->assertStringNotContainsString('{{name}}', $readme);
    }

    #[DataProvider('publishedVersions')]
    public function test_public_manifest_hides_sources_and_marks_large_files_for_download(string $version): void
    {
        $public = BuildGuide::publicManifest($version);
        $files = collect($public['files'])->keyBy('path');

        $this->assertSame('download', $files['package-lock.json']['delivery']);
        $this->assertSame('mcp', $files['package.json']['delivery']);
        $this->assertStringStartsWith('https://', $files['package-lock.json']['download_url']);
        $this->assertStringEndsWith("/api/v1/build-guide/{$version}/files/package-lock.json", $files['package-lock.json']['download_url']);
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
            $this->assertFalse(BuildGuide::isPublished($version), $version);
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
            $this->assertNotSame('', BuildGuide::FROZEN[$version] ?? '', "{$version} är inte låst i BuildGuide::FROZEN");
            $this->assertSame(BuildGuide::FROZEN[$version], hash_file('sha256', BuildGuide::root($version).'/manifest.json'), "{$version} har ändrats efter publicering");
        }
    }

    // Spec delprojekt 3: en katalog utan FROZEN-post syns inte, men hash och verify läser den.
    public function test_unpublished_version_is_hidden_but_readable_by_the_commands(): void
    {
        $this->unpublishedCopy('v99');

        $this->assertNotContains('v99', BuildGuide::versions());
        $this->assertFalse(BuildGuide::isPublished('v99'));
        $this->assertSame('v99', BuildGuide::manifest('v99')['version']);
        $this->assertSame(BuildGuide::file('v1', 'next.config.ts')['content'], BuildGuide::file('v99', 'next.config.ts')['content']);
    }

    public function test_hash_command_refuses_a_frozen_version_and_hashes_an_unpublished_one(): void
    {
        $this->artisan('computercat:byggguide:hash v1')->expectsOutputToContain('är publicerad')->assertExitCode(1);

        $root = $this->unpublishedCopy('v99');
        $manifest = json_decode(file_get_contents("{$root}/v99/manifest.json"), true);
        $manifest['files'] = [];
        file_put_contents("{$root}/v99/manifest.json", json_encode($manifest));
        file_put_contents("{$root}/v99/files/next.config.ts", "export default {};\n");

        $this->artisan('computercat:byggguide:hash v99')->assertExitCode(0);

        $this->assertSame(hash('sha256', "export default {};\n"), BuildGuide::file('v99', 'next.config.ts')['sha256']);
        $this->assertContains('.gitignore', array_column(BuildGuide::manifest('v99')['files'], 'path'));
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
        $root = $this->unpublishedCopy('v99');
        symlink("{$root}/v99/files/package.json", "{$root}/v99/files/lank.json");

        $this->artisan('computercat:byggguide:hash v99')->expectsOutputToContain('symbolisk länk')->assertExitCode(1);
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
    #[DataProvider('publishedVersions')]
    public function test_every_install_command_in_the_guide_ignores_scripts(string $version): void
    {
        $texts = [
            'manifest' => json_encode(BuildGuide::manifest($version), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'CLAUDE.md' => BuildGuide::claudeMd($version),
            'README' => BuildGuide::readme($version, 'Spel'),
        ];

        foreach ($texts as $name => $text) {
            $this->assertStringContainsString('npm ci --ignore-scripts', $text, $name);
            $this->assertDoesNotMatchRegularExpression('/npm ci(?! --ignore-scripts)/', $text, $name);
        }
    }

    // Spec delprojekt 3, "Coolify kör spärren": bara versioner med cc-gate.mjs (v2 och senare).
    public function test_only_versions_with_the_gate_script_run_it_in_the_build(): void
    {
        $this->assertFalse(BuildGuide::runsSecurityChecksInBuild('v1'));
        $this->assertTrue(BuildGuide::runsSecurityChecksInBuild('v2'));
        foreach (['v9', 'x1', ''] as $version) {
            $this->assertFalse(BuildGuide::runsSecurityChecksInBuild($version), $version);
        }
        $this->assertNull(BuildGuide::coolifyBuildCommand('v1'));
    }

    public function test_build_command_is_the_specs_line(): void
    {
        config(['app.url' => 'https://api.computercat.co']);

        $this->assertSame(
            'npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-gate.mjs -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs',
            BuildGuide::coolifyBuildCommand('v2'),
        );
    }

    // Spike A (S10): Coolify sparar build_command i varchar(255) (längre ger 500 och ingen app) och
    // godkänner bara SHELL_SAFE_COMMAND_PATTERN (inga | ; > < $). Planens mönster är strängare.
    public function test_build_command_fits_coolify(): void
    {
        config(['app.url' => 'https://api.computercat.co']);
        $command = (string) BuildGuide::coolifyBuildCommand('v2');

        $this->assertLessThanOrEqual(255, strlen($command));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9._\/=:@,+ -]+\z/', str_replace(' && ', ' ', $command));
        $this->assertDoesNotMatchRegularExpression('/[|;><$`\'"\\\\\n]/', $command);
    }

    public function test_build_command_refuses_a_local_host(): void
    {
        foreach (['http://localhost', 'https://127.0.0.1:8000', 'http://[::1]', 'https://computercat-api.test', 'https://api.localhost'] as $url) {
            config(['app.url' => $url]);
            try {
                BuildGuide::coolifyBuildCommand('v2');
                $this->fail("{$url} borde ha nekats");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Coolify-servern når bara en publik värd', $e->getMessage(), $url);
            }
        }
    }

    // Stänger slingan: adressen i kommandot serverar exakt den låsta filen.
    public function test_build_command_url_serves_the_frozen_gate_script(): void
    {
        config(['app.url' => 'https://api.computercat.co']);
        preg_match('#curl -fsS (\S+) -o#', (string) BuildGuide::coolifyBuildCommand('v2'), $m);
        $this->assertNotEmpty($m[1] ?? null);

        $response = $this->get((string) parse_url($m[1], PHP_URL_PATH));

        $response->assertOk();
        $file = collect(BuildGuide::manifest('v2')['files'])->firstWhere('path', BuildGuide::GATE_SCRIPT);
        $this->assertSame($file['sha256'], hash('sha256', $response->getContent()));
    }

    public function test_too_long_command_is_refused_before_coolify(): void
    {
        config(['app.url' => 'https://'.str_repeat('a', 200).'.example']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('255');
        BuildGuide::coolifyBuildCommand('v2');
    }

    public function test_build_command_requires_a_published_version(): void
    {
        $this->unpublishedCopy('v99', 'v2');
        $this->assertTrue(BuildGuide::runsSecurityChecksInBuild('v99'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('är inte publicerad');
        BuildGuide::coolifyBuildCommand('v99');
    }

    public function test_no_published_version_before_v3_supports_ios(): void
    {
        $this->assertFalse(BuildGuide::supportsIos('v1'));
        $this->assertFalse(BuildGuide::supportsIos('v2'));
        $this->assertFalse(BuildGuide::supportsIos('v99'));
        $this->assertFalse(BuildGuide::supportsIos('../v2'));
    }

    public function test_the_ios_block_must_match_the_contract(): void
    {
        $ok = ['capacitor' => '8.5.3', 'package_manager' => 'spm', 'project' => 'ios/App/App.xcodeproj', 'scheme' => 'App'];
        $this->assertTrue(BuildGuide::iosBlockIsValid($ok));
        $this->assertFalse(BuildGuide::iosBlockIsValid(array_replace($ok, ['project' => 'ios/App/Other.xcodeproj'])));
        $this->assertFalse(BuildGuide::iosBlockIsValid(array_replace($ok, ['package_manager' => 'cocoapods'])));
        $this->assertFalse(BuildGuide::iosBlockIsValid(array_replace($ok, ['capacitor' => '^8'])));
        $this->assertFalse(BuildGuide::iosBlockIsValid(null));
    }

    public function test_version_is_read_from_the_claude_md_line(): void
    {
        $this->assertSame('v2', BuildGuide::versionFromClaudeMd(BuildGuide::claudeMd('v2')));
        $this->assertSame('v1', BuildGuide::versionFromClaudeMd(BuildGuide::claudeMd('v1')));
        $this->assertNull(BuildGuide::versionFromClaudeMd("# CLAUDE.md\nByggguide: v3\n"));
        $this->assertNull(BuildGuide::versionFromClaudeMd("Text Byggguide: **v3**.\n"));
    }
}
