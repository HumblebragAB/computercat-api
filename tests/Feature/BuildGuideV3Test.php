<?php

namespace Tests\Feature;

use App\Releases\ReleaseNext;
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
        $stack = BuildGuide::manifest('v3')['stack'];
        foreach (['@capacitor/core', '@capacitor/ios', '@capacitor/cli'] as $name) {
            $this->assertSame($stack[$name] ?? null, $m[1], $name);
        }
    }

    public function test_check_runs_the_ios_check_last(): void
    {
        $this->assertStringEndsWith('&& node scripts/cc-gate.mjs && node scripts/check-ios.mjs', $this->package()['scripts']['check']);
        $paths = array_column(BuildGuide::manifest('v3')['files'], 'path');
        foreach (['scripts/check-ios.mjs', 'scripts/ios-setup.mjs', 'test/iosSetup.test.ts', 'src/lib/computercat/achievements.ts', 'src/lib/computercat/achievements.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
    }

    // En hänvisning till v2 som version (adresser som /api/v1 är inte versionen). Avsnittet om
    // uppgradering från v2 räknas inte.
    private const V2_REFERENCE = '/byggguide v2|\*\*v2\*\*|"v2"/i';

    private function withoutUpgradeSection(string $md): string
    {
        return (string) preg_replace('/^## Uppgradera ett v2-spel till v3$.*?(?=^## )/ms', '', $md);
    }

    public function test_claude_md_is_v3_and_has_achievements_ios_and_the_testflight_flow(): void
    {
        $md = BuildGuide::claudeMd('v3');

        foreach (['Byggguide: **v3**', '{"version": "v3"}', '`Grundkod från byggguide v3`', '"build_guide": "v3"', '## Achievements', '`achievements.report', '`upsert_achievement`', 'hela listan', '`unknown`', '`rejected`', '`dropped`', '## iOS (Capacitor)', '`node scripts/ios-setup.mjs <bundle_id> "<name>"`', '`targets.app_store.ios.bundle_id`', '`check-ios: OK`', '## Skicka till TestFlight', '## Uppgradera ett v2-spel till v3', '`npm run build` ska klara sig utan nätverk', '`const { env } = process`', 'inte avsiktligt skadlig spelkod'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertMatchesRegularExpression('/^'.preg_quote(ReleaseNext::TESTFLIGHT_FLOW, '/').'$/m', $md);
        $this->assertSame('v3', BuildGuide::versionFromClaudeMd($md));
        $this->assertStringNotContainsString('(bygget ska klara sig utan nätverk)', $md);
        $this->assertDoesNotMatchRegularExpression(self::V2_REFERENCE, $this->withoutUpgradeSection($md));
        $this->assertStringContainsString('## Exemplets topplista', $this->withoutUpgradeSection($md));
    }

    // R10: listan i CLAUDE.md är exakt de filer som är nya eller ändrade jämfört med v2, och v3 tar inte bort någon.
    public function test_the_upgrade_list_is_exactly_the_files_v3_adds_or_changes(): void
    {
        $v2 = array_column(BuildGuide::manifest('v2')['files'], 'sha256', 'path');
        $v3 = BuildGuide::manifest('v3')['files'];
        $changed = [];
        foreach ($v3 as $f) {
            if (($v2[$f['path']] ?? null) !== $f['sha256']) {
                $changed[] = $f['path'];
            }
        }
        preg_match('/^Filer som är nya eller ändrade i v3 jämfört med v2:\n\n((?:- `[^`]+`\n)+)/m', BuildGuide::claudeMd('v3'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'Listan saknas i CLAUDE.md.');
        preg_match_all('/^- `([^`]+)`$/m', $m[1], $listed);
        $l = $listed[1];
        sort($changed);
        sort($l);
        $this->assertSame($changed, $l);
        $this->assertSame([], array_values(array_diff(array_keys($v2), array_column($v3, 'path'))));
    }

    public function test_readme_template_names_v3_achievements_and_ios(): void
    {
        $readme = BuildGuide::readme('v3', 'Spel');

        foreach (['enligt Computercats byggguide v3', '`achievements.report/list/mine`', '## iOS och TestFlight', '`node scripts/ios-setup.mjs', 'och iOS-kontrollen', 'inte avsiktligt skadlig spelkod'] as $text) {
            $this->assertStringContainsString($text, $readme);
        }
        $this->assertDoesNotMatchRegularExpression(self::V2_REFERENCE, $readme);
    }

    // TestFlight-planen Task 4 punkt 1: kontraktet, och Capacitor i stacken i samma version.
    public function test_manifest_has_the_ios_contract(): void
    {
        $m = BuildGuide::manifest('v3');

        $this->assertSame(['capacitor' => '8.5.3', 'package_manager' => 'spm', 'project' => 'ios/App/App.xcodeproj', 'scheme' => 'App'], $m['ios']);
        $this->assertTrue(BuildGuide::iosBlockIsValid($m['ios']));
        foreach (['@capacitor/core', '@capacitor/ios', '@capacitor/cli'] as $package) {
            $this->assertSame($m['ios']['capacitor'], $m['stack'][$package], $package);
        }
    }

    public function test_docs_test_guards_the_new_texts(): void
    {
        $docs = BuildGuide::file('v3', 'test/docs.test.ts')['content'];

        foreach (["'\"build_guide\": \"v3\"'", "'achievements.report'", "'scripts/ios-setup.mjs'", "'scripts/check-ios.mjs'", "'promote_release(target: app_store, stage: testflight'"] as $text) {
            $this->assertStringContainsString($text, $docs);
        }
    }
}
