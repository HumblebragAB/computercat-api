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

    public function test_seed_articles_apply_to_every_build_guide_version(): void
    {
        foreach ((new FactBank)->all() as $fact) {
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
        $this->assertSame('security-hemligheter-i-klienten', $bank->search('api-nyckel hemlighet')[0]['id']);
        $this->assertSame('process-ide-till-live', $bank->search('request_live')[0]['id']);
    }
}
