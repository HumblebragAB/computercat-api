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
        ] as $query => $id) {
            $this->assertSame($id, $bank->search($query)[0]['id'] ?? null, $query);
        }
        $this->assertSame('security-hemligheter-i-klienten', $bank->search('api-nyckel hemlighet')[0]['id']);
        $this->assertSame('process-ide-till-live', $bank->search('request_live')[0]['id']);
    }
}
