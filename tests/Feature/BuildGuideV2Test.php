<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use Tests\TestCase;

// Byggguide v2 (spec delprojekt 3, del 3, "Innehåll i v2 jämfört med v1"). Läser katalogen
// även innan v2 är låst; BuildGuide::manifest kräver ingen låsning.
class BuildGuideV2Test extends TestCase
{
    // En hänvisning till v1 i v2:s texter (adresser som /api/v1 är inte versionen).
    private const V1_REFERENCE = '/byggguide v1|\*\*v1\*\*|"v1"/i';

    private function package(): array
    {
        return json_decode(BuildGuide::file('v2', 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_claude_md_is_v2_throughout_and_has_the_fact_bank_section(): void
    {
        $md = BuildGuide::claudeMd('v2');

        foreach (['Byggguide: **v2**', '{"version": "v2"}', '`Grundkod från byggguide v2`', '## Faktabanken', '`search_facts`', '`get_fact`', '`list_facts`', '"build_guide": "v2"', 'går före din egen bedömning', 'fråga en människa', '## Säkerhetsspärren i Coolify', '`scripts/cc-gate.mjs`', 'npm-registret svarade inte', 'inte avsiktligt skadlig spelkod', 'bara i utvecklingsberoenden'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertDoesNotMatchRegularExpression(self::V1_REFERENCE, $md);
    }

    public function test_readme_template_names_v2(): void
    {
        $readme = BuildGuide::readme('v2', 'Spel');

        $this->assertStringContainsString('enligt Computercats byggguide v2', $readme);
        $this->assertStringContainsString('scripts/cc-gate.mjs', $readme);
        $this->assertStringContainsString('inte avsiktligt skadlig spelkod', $readme);
        $this->assertStringContainsString('bara i utvecklingsberoenden', $readme);
        $this->assertDoesNotMatchRegularExpression(self::V1_REFERENCE, $readme);
    }

    public function test_manifest_is_v2_and_commits_as_v2(): void
    {
        $m = BuildGuide::manifest('v2');

        $this->assertSame('v2', $m['version']);
        $this->assertStringContainsString('Grundkod från byggguide v2', implode("\n", $m['steps']));
        $this->assertDoesNotMatchRegularExpression(self::V1_REFERENCE, json_encode($m['steps'], JSON_UNESCAPED_UNICODE));
    }

    // Spec punkt 4: testerna körs aldrig med React i produktionsläge.
    public function test_tests_run_with_node_env_test(): void
    {
        $this->assertSame('NODE_ENV=test vitest run', $this->package()['scripts']['test']);
    }

    public function test_docs_test_guards_the_fact_bank_and_the_security_scripts(): void
    {
        $docs = BuildGuide::file('v2', 'test/docs.test.ts')['content'];

        foreach (["'search_facts'", "'get_fact'", "'list_facts'", "'\"build_guide\": \"v2\"'", "'scripts/cc-gate.mjs'"] as $text) {
            $this->assertStringContainsString($text, $docs);
        }
    }

    // Spec punkt 1, 2 och 6: säkerhetsskriptet finns, körs sist i npm run check och testas i guiden.
    public function test_security_script_is_in_the_guide_and_runs_last_in_check(): void
    {
        $paths = array_column(BuildGuide::manifest('v2')['files'], 'path');

        foreach (['scripts/cc-gate.mjs', 'src/lib/computercat/csp.ts', 'src/lib/computercat/csp.test.ts', 'test/security.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
        $this->assertSame(
            'npm run typecheck && npm run lint && npm test && NEXT_TELEMETRY_DISABLED=1 npm run build && node scripts/check-export.mjs && node scripts/cc-gate.mjs',
            $this->package()['scripts']['check'],
        );
        $layout = BuildGuide::file('v2', 'src/app/layout.tsx')['content'];
        $this->assertStringContainsString('contentSecurityPolicy(process.env.NEXT_PUBLIC_COMPUTERCAT_URL)', $layout);
        $this->assertStringContainsString("process.env.NODE_ENV === 'production'", $layout);
    }

    public function test_audit_script_is_in_the_guide(): void
    {
        $paths = array_column(BuildGuide::manifest('v2')['files'], 'path');

        $gate = BuildGuide::file('v2', 'scripts/cc-gate.mjs')['content'];

        $this->assertContains('test/ccAudit.test.ts', $paths);
        $this->assertStringContainsString("'--omit=dev', '--audit-level=high'", $gate);
        $this->assertStringContainsString('!/^npm_config_/i.test(key)', $gate);
    }

    public function test_eslint_has_the_security_rules(): void
    {
        $config = BuildGuide::file('v2', 'eslint.config.mjs')['content'];

        foreach (["'no-eval': 'error'", "'no-implied-eval': 'error'", "'no-new-func': 'error'", "Property[key.name='dangerouslySetInnerHTML']", 'NEXT_PUBLIC_COMPUTERCAT_(GAME|URL)'] as $text) {
            $this->assertStringContainsString($text, $config);
        }
        $this->assertContains('test/eslintRules.test.ts', array_column(BuildGuide::manifest('v2')['files'], 'path'));
    }

    // R8: braces accepteras som dev-only; ingen override, och texterna säger varför.
    public function test_braces_is_accepted_without_override(): void
    {
        $this->assertSame(['vite' => '7.3.7'], $this->package()['overrides']);
        $this->assertSame(['vite' => '7.3.7'], BuildGuide::manifest('v2')['overrides']);
    }
}
