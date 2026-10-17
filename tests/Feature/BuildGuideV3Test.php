<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use Tests\TestCase;

// Byggguide v3 (plan 2026-10-16-byggguide-v3): achievements i klienten och Capacitor iOS.
// Läser katalogen även innan v3 är låst; BuildGuide::manifest kräver ingen låsning.
class BuildGuideV3Test extends TestCase
{
    private function package(): array
    {
        return json_decode(BuildGuide::file('v3', 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_manifest_is_v3_and_commits_as_v3(): void
    {
        $m = BuildGuide::manifest('v3');

        $this->assertSame('v3', $m['version']);
        $this->assertStringContainsString('Grundkod från byggguide v3', implode("\n", $m['steps']));
        $this->assertStringNotContainsString('v2', json_encode($m['steps'], JSON_UNESCAPED_UNICODE));
    }

    // R4, R5: Capacitor exakt, jsdom som klarar Coolifys Node 22.14, version att höja.
    public function test_capacitor_is_pinned_and_jsdom_runs_on_coolifys_node(): void
    {
        $p = $this->package();

        $this->assertSame('8.5.3', $p['dependencies']['@capacitor/core']);
        $this->assertSame('8.5.3', $p['dependencies']['@capacitor/ios']);
        $this->assertSame('8.5.3', $p['devDependencies']['@capacitor/cli']);
        $this->assertArrayNotHasKey('@capacitor/cli', $p['dependencies']);
        $this->assertSame('29.1.1', $p['devDependencies']['jsdom']);
        $this->assertSame('0.1.0', $p['version']);
        $this->assertSame('22.x', $p['engines']['node']);
        $this->assertSame($p['dependencies'] + $p['devDependencies'], BuildGuide::manifest('v3')['stack']);
    }

    // R11: spärren och policyn är v2:s, byte för byte.
    public function test_the_gate_and_the_policy_are_unchanged_from_v2(): void
    {
        foreach (['scripts/cc-gate.mjs', 'scripts/check-export.mjs', 'src/lib/computercat/csp.ts'] as $path) {
            $this->assertSame(BuildGuide::file('v2', $path)['sha256'], BuildGuide::file('v3', $path)['sha256'], $path);
        }
    }

    // Eriks krav: appId kommer från spelet. Ingen fil som följer med till spelet (utom testfixturer)
    // har ett färdigt bundle-id, och capacitor.config.ts och ios/ är spelets egna (TestFlight-planen
    // Task 4 punkt 4). Mönstret i check-ios.mjs står med \. och träffas inte.
    public function test_no_guide_file_hardcodes_a_bundle_id_and_ios_files_are_per_game(): void
    {
        $checked = 0;
        foreach (BuildGuide::manifest('v3')['files'] as $f) {
            $this->assertNotSame('capacitor.config.ts', $f['path']);
            $this->assertStringStartsNotWith('ios/', $f['path']);
            if (str_starts_with($f['path'], 'test/') || str_ends_with($f['path'], '.test.ts') || $f['path'] === 'package-lock.json') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/se\.computercat\.[a-z][a-z0-9]/', BuildGuide::file('v3', $f['path'])['content'], $f['path']);
            $checked++;
        }
        $this->assertGreaterThan(30, $checked);
    }

    // check-ios.mjs har guidens Capacitor-version som konstant; den får inte glida från manifestet.
    public function test_the_ios_check_pins_the_manifests_capacitor(): void
    {
        $script = BuildGuide::file('v3', 'scripts/check-ios.mjs')['content'];

        $this->assertSame(1, preg_match("/^export const CAPACITOR = '([0-9.]+)';$/m", $script, $m));
        $this->assertSame(BuildGuide::manifest('v3')['ios']['capacitor'] ?? '8.5.3', $m[1]);
    }

    public function test_check_runs_the_ios_check_last(): void
    {
        $this->assertStringEndsWith('&& node scripts/cc-gate.mjs && node scripts/check-ios.mjs', $this->package()['scripts']['check']);
        $paths = array_column(BuildGuide::manifest('v3')['files'], 'path');
        foreach (['scripts/check-ios.mjs', 'scripts/ios-setup.mjs', 'test/iosSetup.test.ts', 'src/lib/computercat/achievements.ts', 'src/lib/computercat/achievements.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
    }
}
