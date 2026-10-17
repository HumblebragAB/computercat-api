<?php

namespace Tests\Feature\Matches;

use App\Matches\Rules\RevealRules;
use App\Matches\RulesetRegistry;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Den riktiga configen: spelet reveal får regeluppsättningen reveal. */
class RevealRulesetRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reveal_game_gets_reveal_rules(): void
    {
        $game = Game::create(['slug' => 'reveal', 'name' => 'Reveal', 'is_active' => true]);

        $this->assertInstanceOf(RevealRules::class, RulesetRegistry::for($game, 'reveal'));
        $this->assertSame(['reveal'], RulesetRegistry::forGame($game));
    }
}
