<?php

namespace Tests\Feature\Matches;

use App\Matches\InviteTokens;
use App\Matches\MatchError;
use App\Matches\MatchService;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Matches\FakeRules;

/**
 * Kort kod som inbjudan (invite => code i config/matches.php, Reveal): fyra tecken som
 * motspelaren skriver in för hand. Lagras som HMAC med app.key, aldrig som klartext eller
 * ren sha256. Regeluppsättningar utan invite får fortfarande 43 teckens token.
 */
class MatchInviteCodeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/games/reveal/matches';

    private const CODE = '/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}\z/';

    private Game $game;

    private User $anna;

    private User $bo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'reveal', 'name' => 'Reveal', 'is_active' => true]);
        $this->anna = User::factory()->create();
        $this->bo = User::factory()->create();
    }

    private function service(): MatchService
    {
        return app(MatchService::class);
    }

    /** @return array{match: GameMatch, token: string} */
    private function create(?User $user = null): array
    {
        return $this->service()->create($this->game, $user ?? $this->anna, 'reveal', [], 'Anna');
    }

    private function matchError(callable $fn, string $code): void
    {
        try {
            $fn();
        } catch (MatchError $e) {
            $this->assertSame($code, $e->errorCode);

            return;
        }
        $this->fail("Expected MatchError {$code}");
    }

    /** Generator som ger koderna i tur och ordning, så att en krock kan framkallas. */
    private function fixedCodes(string ...$codes): void
    {
        $this->app->instance(InviteTokens::class, new class($codes) extends InviteTokens
        {
            public function __construct(private array $codes) {}

            protected function generateCode(): string
            {
                return array_shift($this->codes) ?? throw new \LogicException('Slut på testkoder.');
            }
        });
    }

    private function joinOverHttp(User $user, string $token)
    {
        Sanctum::actingAs($user);

        return $this->postJson(self::BASE.'/join', ['token' => $token]);
    }

    public function test_reveal_create_returns_a_four_character_code_stored_as_hmac(): void
    {
        ['match' => $match, 'token' => $code] = $this->create();

        $this->assertMatchesRegularExpression(self::CODE, $code);
        $stored = $match->fresh()->join_token_hash;
        $this->assertSame(hash_hmac('sha256', $code, config('app.key')), $stored);
        $this->assertNotSame(hash('sha256', $code), $stored);
        $this->assertNotSame($code, $stored);
        $this->assertSame(64, strlen($stored));
    }

    public function test_http_create_returns_the_code_as_join_token(): void
    {
        Sanctum::actingAs($this->anna);
        $created = $this->postJson(self::BASE, ['ruleset' => 'reveal'])->assertCreated();

        $this->assertSame(['match', 'join_token'], array_keys($created->json()));
        $this->assertMatchesRegularExpression(self::CODE, $created->json('join_token'));
    }

    public function test_join_normalizes_case_and_whitespace(): void
    {
        $code = $this->create()['token'];
        $typed = '  '.strtolower(substr($code, 0, 2)).' '.strtolower(substr($code, 2))."\n";

        $match = $this->service()->join($this->game, $this->bo, $typed, 'Bo');

        $this->assertSame(GameMatch::STATUS_ACTIVE, $match->status);
        $this->assertNull($match->fresh()->join_token_hash);
    }

    public function test_wrong_used_expired_and_foreign_codes_all_look_the_same(): void
    {
        $this->fixedCodes('AAAA', 'BBBB', 'CCCC', 'DDDD');
        $other = Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true]);
        $carl = User::factory()->create();

        $this->create();
        $this->matchError(fn () => $this->service()->join($this->game, $this->bo, 'ZZZZ', null), 'invite_expired');
        // Tecken utanför alfabetet (O, I, L) har giltig form men träffar aldrig.
        $this->matchError(fn () => $this->service()->join($this->game, $this->bo, 'OILO', null), 'invite_expired');

        $this->create($carl);
        $this->service()->join($this->game, $this->bo, 'BBBB', null);
        $this->matchError(fn () => $this->service()->join($this->game, $this->anna, 'BBBB', null), 'invite_expired');

        $this->create();
        $this->travel(16)->minutes();
        $this->matchError(fn () => $this->service()->join($this->game, $this->bo, 'CCCC', null), 'invite_expired');

        $this->create(User::factory()->create());
        $this->matchError(fn () => $this->service()->join($other, $this->bo, 'DDDD', null), 'invite_expired');
    }

    public function test_own_match_does_not_consume_the_code(): void
    {
        $code = $this->create()['token'];

        $this->matchError(fn () => $this->service()->join($this->game, $this->anna, strtolower($code), null), 'own_match');

        $this->assertSame(GameMatch::STATUS_ACTIVE, $this->service()->join($this->game, $this->bo, $code, null)->status);
    }

    public function test_generator_collision_is_retried(): void
    {
        $this->fixedCodes('AAAA', 'AAAA', 'AAAA', 'BBBB');

        ['match' => $first, 'token' => $a] = $this->create();
        ['match' => $second, 'token' => $b] = $this->create($this->bo);

        $this->assertSame('AAAA', $a);
        $this->assertSame('BBBB', $b);
        $this->assertSame(2, GameMatch::count());
        $this->assertNotSame($first->fresh()->join_token_hash, $second->fresh()->join_token_hash);
    }

    public function test_endless_collisions_fail_loud(): void
    {
        $this->fixedCodes(...array_fill(0, 20, 'AAAA'));
        $this->create();

        try {
            $this->create($this->bo);
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(MatchError::class, $e);
            $this->assertStringContainsString('inbjudningskod', $e->getMessage());
        }
        $this->assertSame(1, GameMatch::count());
    }

    public function test_invite_rotation_gives_a_new_code_and_the_old_stops_working(): void
    {
        $this->fixedCodes('AAAA', 'BBBB');
        ['match' => $match, 'token' => $old] = $this->create();

        $new = $this->service()->invite($match, $this->anna);

        $this->assertSame('BBBB', $new);
        $this->assertSame(hash_hmac('sha256', 'BBBB', config('app.key')), $match->fresh()->join_token_hash);
        $this->matchError(fn () => $this->service()->join($this->game, $this->bo, $old, null), 'invite_expired');
        $this->assertSame(GameMatch::STATUS_ACTIVE, $this->service()->join($this->game, $this->bo, 'bbbb', null)->status);
    }

    public function test_invite_rotation_over_http_returns_a_code(): void
    {
        ['match' => $match] = $this->create();
        Sanctum::actingAs($this->anna);

        $new = $this->postJson(self::BASE."/{$match->id}/invite")->assertOk()->json('join_token');

        $this->assertMatchesRegularExpression(self::CODE, $new);
        // Koden står aldrig som värde i vyn.
        $view = $this->getJson(self::BASE."/{$match->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('"'.$new.'"', $view);
        $this->assertStringNotContainsString('join_token', $view);
    }

    public function test_ruleset_without_invite_config_gets_long_tokens(): void
    {
        config(['matches.rulesets.fake' => ['class' => FakeRules::class, 'games' => ['reveal']]]);

        ['match' => $match, 'token' => $token] = $this->service()->create($this->game, $this->anna, 'fake', [], null);

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $this->assertSame(hash('sha256', $token), $match->fresh()->join_token_hash);

        $rotated = $this->service()->invite($match, $this->anna);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $rotated);
        $this->assertSame(hash('sha256', $rotated), $match->fresh()->join_token_hash);

        // Token är skiftlägeskänslig och normaliseras inte.
        if (strtolower($rotated) !== $rotated) {
            $this->matchError(fn () => $this->service()->join($this->game, $this->bo, strtolower($rotated), null), 'invite_expired');
        }
        $this->assertSame(GameMatch::STATUS_ACTIVE, $this->service()->join($this->game, $this->bo, $rotated, null)->status);
    }

    public function test_unknown_invite_type_fails_loud(): void
    {
        config(['matches.rulesets.reveal.invite' => 'pin']);

        $this->expectException(\LogicException::class);
        $this->create();
    }

    public function test_failed_joins_are_limited_per_user(): void
    {
        $carl = User::factory()->create();

        // Utspritt över timmen så att match-join (10/min) inte slår till först.
        for ($i = 1; $i <= 20; $i++) {
            if ($i % 10 === 0) {
                $this->travel(61)->seconds();
            }
            $this->joinOverHttp($carl, 'ZZZZ')->assertStatus(410);
        }

        // Spärren gäller före uppslaget: även en giltig kod ger 429.
        $code = $this->create()['token'];
        $this->joinOverHttp($carl, $code)
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        $this->assertSame(GameMatch::STATUS_WAITING, GameMatch::sole()->status);

        // Andra användare påverkas inte, och spärren släpper efter en timme.
        $this->joinOverHttp($this->bo, $code)->assertOk()->assertJsonPath('status', 'active');

        $this->travel(61)->minutes();
        $this->joinOverHttp($carl, 'ZZZZ')->assertStatus(410);
    }

    public function test_successful_joins_do_not_count_as_failures(): void
    {
        $players = User::factory()->count(6)->create();
        $joiner = $this->bo;

        // 25 lyckade join för samma användare (över 20) under timmen ger ingen spärr.
        for ($i = 0; $i < 25; $i++) {
            if ($i > 0 && $i % 5 === 0) {
                $this->travel(61)->seconds();
            }
            $code = $this->create($players[$i % 6])['token'];
            $this->joinOverHttp($joiner, $code)->assertOk();
            GameMatch::query()->update(['status' => GameMatch::STATUS_FINISHED]);
        }

        $this->joinOverHttp($joiner, 'ZZZZ')->assertStatus(410)->assertJsonPath('code', 'invite_expired');
    }

    public function test_failed_joins_are_limited_per_ip(): void
    {
        $users = User::factory()->count(4)->create();

        for ($i = 0; $i < 60; $i++) {
            if ($i > 0 && $i % 10 === 0) {
                $this->travel(61)->seconds();
            }
            $this->joinOverHttp($users[intdiv($i, 15)], 'ZZZZ')->assertStatus(410);
        }

        $fresh = User::factory()->create();
        $this->joinOverHttp($fresh, 'ZZZZ')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_own_match_is_not_a_failed_join(): void
    {
        $code = $this->create()['token'];

        for ($i = 0; $i < 25; $i++) {
            if ($i > 0 && $i % 9 === 0) {
                $this->travel(61)->seconds();
            }
            $this->joinOverHttp($this->anna, $code)->assertStatus(409)->assertJsonPath('code', 'own_match');
        }
    }
}
