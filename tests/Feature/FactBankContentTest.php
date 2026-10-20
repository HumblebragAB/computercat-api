<?php

namespace Tests\Feature;

use App\Services\FactBank;
use Tests\TestCase;

// Den riktiga faktabanken måste alltid gå att läsa: en trasig artikel fäller sviten.
class FactBankContentTest extends TestCase
{
    public function test_all_articles_are_valid(): void
    {
        $facts = (new FactBank)->all();

        $this->assertGreaterThanOrEqual(6, count($facts));
        foreach (['stack', 'testing', 'security', 'process'] as $topic) {
            $this->assertNotEmpty((new FactBank)->all($topic), $topic);
        }
    }

    // Etapp A:s fröartiklar gäller både v1- och v2-spel. Etapp C får ha build_guide: [v2].
    private const SEED_ARTICLES = [
        'process-ide-till-live', 'process-slapp-engangssteg', 'process-slappflode', 'security-hemligheter-i-klienten',
        'stack-ingen-egen-backend', 'stack-nytt-beroende', 'stack-valordning', 'testing-vad-som-testas',
    ];

    public function test_seed_articles_apply_to_every_build_guide_version(): void
    {
        $seeds = array_filter((new FactBank)->all(), fn (array $fact) => in_array($fact['id'], self::SEED_ARTICLES, true));
        $this->assertCount(count(self::SEED_ARTICLES), $seeds);
        foreach ($seeds as $fact) {
            $this->assertNull($fact['build_guide'], $fact['id']);
            $this->assertStringNotContainsString('byggguide/v2', $fact['markdown'], $fact['id']);
        }
    }

    public function test_common_questions_find_the_right_article(): void
    {
        $bank = new FactBank;

        $this->assertSame('stack-nytt-beroende', $bank->search('lägga till npm-paket')[0]['id']);
        $this->assertSame('stack-ingen-egen-backend', $bank->search('egen backend databas')[0]['id']);
        $this->assertSame('testing-vad-som-testas', $bank->search('vilka tester')[0]['id']);
        // Etapp C.
        foreach ([
            'bygget föll' => 'security-ratta-sparrfel', 'extern font' => 'security-csp', 'anti cheat' => 'security-anti-cheat',
            'deploy_failed' => 'process-provisioning-status', 'analytics' => 'stack-ingen-tredjepartssparning',
            'lint regel' => 'security-lint-regler', 'npm run check' => 'testing-npm-run-check', 'sparfil' => 'security-sparfiler',
            'offline kö' => 'practice-offline-kon', 'fråga en människa' => 'process-fraga-manniska',
            'skicka till testflight' => 'process-slappflode', 'höja version package.json' => 'process-slappflode',
            'bundle-id app store' => 'process-slapp-engangssteg',
            'rapportera achievements' => 'practice-achievements', 'unknown rejected upplåsning' => 'practice-achievements',
            'ios-projekt capacitor' => 'process-ios-capacitor', 'ios-setup' => 'process-ios-capacitor',
            'tvåspelarmatch' => 'practice-matcher', 'matcher stale' => 'practice-matcher',
            // Butiksresurser (Pawtender 2026-10-20: ingen vägledning om ikon, startbild och skärmdumpar).
            'ikon' => 'process-butiksresurser', 'appikon' => 'process-butiksresurser', 'app icon' => 'process-butiksresurser',
            'splash' => 'process-butiksresurser', 'startbild' => 'process-butiksresurser', 'skärmdumpar' => 'process-butiksresurser',
            'screenshots' => 'process-butiksresurser', 'favicon' => 'process-butiksresurser',
            'skärmdumpar app store' => 'process-butiksresurser', 'byta appikon' => 'process-butiksresurser',
        ] as $query => $id) {
            $this->assertSame($id, $bank->search($query)[0]['id'] ?? null, $query);
        }
        $this->assertSame('security-hemligheter-i-klienten', $bank->search('api-nyckel hemlighet')[0]['id']);
        $this->assertSame('process-ide-till-live', $bank->search('request_live')[0]['id']);
        $this->assertSame('process-pusha-varje-steg', $bank->search('vänta med att pusha')[0]['id']);
    }

    // Byggguide v3: artiklarna som gällde v2 gäller också v3, och de nya gäller bara v3.
    public function test_v2_articles_also_apply_to_v3(): void
    {
        $bank = new FactBank;
        foreach ($bank->all() as $fact) {
            if (is_array($fact['build_guide']) && in_array('v2', $fact['build_guide'], true)) {
                $this->assertContains('v3', $fact['build_guide'], $fact['id']);
            }
        }
        $ids = array_column($bank->all(null, 'v3'), 'id');
        $this->assertContains('practice-achievements', $ids);
        $this->assertContains('process-ios-capacitor', $ids);
        $this->assertContains('practice-matcher', $ids);
        $this->assertContains('process-butiksresurser', $ids);
        $this->assertNotContains('practice-achievements', array_column($bank->all(null, 'v2'), 'id'));
    }
}
