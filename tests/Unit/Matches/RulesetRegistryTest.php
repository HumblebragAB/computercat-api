<?php

namespace Tests\Unit\Matches;

use App\Matches\RulesetRegistry;
use App\Models\Game;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

class RulesetRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['matches.rulesets' => ['reveal' => ['class' => FakeRules::class, 'games' => ['reveal']]]]);
    }

    private function game(string $slug): Game
    {
        return new Game(['slug' => $slug]);
    }

    public function test_for_returns_rules_instance(): void
    {
        $this->assertInstanceOf(FakeRules::class, RulesetRegistry::for($this->game('reveal'), 'reveal'));
    }

    public function test_for_throws_for_unlisted_game(): void
    {
        try {
            RulesetRegistry::for($this->game('tocco'), 'reveal');
            $this->fail('should throw');
        } catch (AuthorizationException $e) {
            $this->assertSame('ruleset_not_allowed', $e->getMessage());
        }
    }

    public function test_for_throws_for_unknown_ruleset(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('ruleset_not_allowed');
        RulesetRegistry::for($this->game('reveal'), 'nope');
    }

    public function test_for_game_lists_rulesets(): void
    {
        $this->assertSame(['reveal'], RulesetRegistry::forGame($this->game('reveal')));
        $this->assertSame([], RulesetRegistry::forGame($this->game('tocco')));
    }
}
