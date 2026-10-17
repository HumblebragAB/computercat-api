<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MatchApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/games/reveal/matches';

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

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** @return array{id: string, token: string} */
    private function createMatch(): array
    {
        $response = $this->as($this->anna)->postJson(self::BASE, ['ruleset' => 'reveal', 'name' => 'Anna'])->assertCreated();

        return ['id' => $response->json('match.id'), 'token' => $response->json('join_token')];
    }

    private function startedMatch(): string
    {
        ['id' => $id, 'token' => $token] = $this->createMatch();
        $this->as($this->bo)->postJson(self::BASE.'/join', ['token' => $token, 'name' => 'Bo'])->assertOk();

        return $id;
    }

    private function version(string $id): int
    {
        return GameMatch::findOrFail($id)->version;
    }

    private function act(User $user, string $id, string $type, array $payload = [], ?int $version = null): TestResponse
    {
        return $this->as($user)->postJson(self::BASE."/{$id}/actions", [
            'type' => $type,
            'payload' => $payload,
            'version' => $version ?? $this->version($id),
        ]);
    }

    private function poll(User $user, string $id, array $headers = [], string $query = ''): TestResponse
    {
        return $this->as($user)->getJson(self::BASE."/{$id}".$query, $headers);
    }

    private function etagOf(TestResponse $response): string
    {
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);

        return $etag;
    }

    public function test_full_flow_over_http(): void
    {
        $created = $this->as($this->anna)->postJson(self::BASE, ['ruleset' => 'reveal', 'name' => 'Anna']);
        $created->assertCreated()
            ->assertJsonPath('match.status', 'waiting')
            ->assertJsonPath('match.your_seat', 0);
        $this->assertSame(['match', 'join_token'], array_keys($created->json()));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $created->json('join_token'));
        $id = $created->json('match.id');

        $joined = $this->as($this->bo)->postJson(self::BASE.'/join', ['token' => $created->json('join_token'), 'name' => 'Bo']);
        $joined->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('your_seat', 1)
            ->assertJsonPath('state.phase', 'choosing');

        $this->act($this->anna, $id, 'set_secret', ['value' => 10])->assertOk()->assertJsonPath('you.secret', 10);
        $locked = $this->act($this->bo, $id, 'set_secret', ['value' => 20])->assertOk();
        $locked->assertJsonPath('state.phase', 'guessing')->assertJsonPath('you.secret', 20);
        $this->assertStringNotContainsString('"secret":10', $locked->getContent());

        $this->act($this->anna, $id, 'guess', ['value' => 30])->assertOk()->assertJsonPath('state.guesses.0.0.result', 'lower');
        $this->act($this->bo, $id, 'guess', ['value' => 5])->assertOk()->assertJsonPath('state.guesses.1.0.result', 'higher');
        $this->act($this->anna, $id, 'guess', ['value' => 20])->assertOk()->assertJsonPath('state.last_chance', true);
        $end = $this->act($this->bo, $id, 'guess', ['value' => 50])->assertOk();
        $end->assertJsonPath('state.phase', 'round_over')->assertJsonPath('state.score', [1, 0]);
        $roundEnd = collect($end->json('events'))->firstWhere('type', 'round_end');
        $this->assertSame([10, 20], $roundEnd['payload']['secrets']);

        $active = $this->as($this->anna)->getJson(self::BASE.'/active')->assertOk();
        $this->assertSame([['id' => $id, 'ruleset' => 'reveal', 'status' => 'active', 'your_seat' => 0]], $active->json('data'));

        $this->as($this->bo)->postJson(self::BASE."/{$id}/leave")->assertOk()->assertJsonPath('status', 'abandoned');
        $this->as($this->anna)->getJson(self::BASE.'/active')->assertOk()->assertExactJson(['data' => []]);
        $this->poll($this->anna, $id)->assertOk()->assertJsonPath('status', 'abandoned');
    }

    public function test_rematch_route(): void
    {
        $id = $this->startedMatch();

        // Fel läge innan matchen är slut.
        $this->as($this->anna)->postJson(self::BASE."/{$id}/rematch")
            ->assertStatus(422)->assertJsonPath('code', 'wrong_phase');

        GameMatch::whereKey($id)->update(['status' => GameMatch::STATUS_FINISHED]);

        $this->as($this->anna)->postJson(self::BASE."/{$id}/rematch")->assertOk()->assertJsonPath('id', $id);
        $both = $this->as($this->bo)->postJson(self::BASE."/{$id}/rematch")->assertOk();
        $rematch = collect($both->json('events'))->firstWhere('type', 'rematch');
        $this->assertNotNull($rematch);
        $this->poll($this->bo, $rematch['payload']['match_id'])->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_poll_304_until_change(): void
    {
        $id = $this->startedMatch();
        $this->poll($this->bo, $id)->assertOk(); // Bo ansluten, så ETag är stabil.

        $first = $this->poll($this->anna, $id)->assertOk();
        $etag = $this->etagOf($first);
        $this->assertSame('"'.$this->version($id).'-11"', $etag);

        $notModified = $this->poll($this->anna, $id, ['If-None-Match' => $etag]);
        $notModified->assertStatus(304);
        $this->assertSame('', $notModified->getContent());

        $this->act($this->bo, $id, 'set_secret', ['value' => 20])->assertOk();

        $changed = $this->poll($this->anna, $id, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $this->etagOf($changed));
        $this->assertSame([false, true], $changed->json('state.locked'));
    }

    public function test_poll_accepts_weak_etag(): void
    {
        $id = $this->startedMatch();
        $etag = $this->etagOf($this->poll($this->anna, $id)->assertOk());

        $this->poll($this->anna, $id, ['If-None-Match' => 'W/'.$etag])->assertStatus(304);
    }

    public function test_etag_changes_when_opponent_disconnects(): void
    {
        $id = $this->startedMatch();
        $this->poll($this->bo, $id)->assertOk();

        $connected = $this->poll($this->anna, $id)->assertOk();
        $this->assertTrue($connected->json('players.1.connected'));
        $etag = $this->etagOf($connected);

        $this->travel(11)->seconds();

        $after = $this->poll($this->anna, $id, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $this->etagOf($after));
        $this->assertFalse($after->json('players.1.connected'));
        $this->assertTrue($after->json('players.0.connected'));
    }

    public function test_global_limit_removed_and_poll_limit_applies(): void
    {
        $id = $this->startedMatch();

        for ($i = 1; $i <= 120; $i++) {
            $status = $this->poll($this->anna, $id)->status();
            $this->assertSame(200, $status, "Poll {$i} gav {$status}");
        }

        $this->poll($this->anna, $id)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_error_shapes(): void
    {
        $id = $this->startedMatch();

        // 409 stale med aktuell version.
        $current = $this->version($id);
        $this->act($this->anna, $id, 'set_secret', ['value' => 10], $current - 1)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Matchen har ändrats. Hämta den igen.', 'code' => 'stale', 'version' => $current]);

        // 422 invalid_payload för tal som inte är JSON-heltal.
        $this->act($this->anna, $id, 'set_secret', ['value' => '37'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_payload')->assertJsonStructure(['message', 'code']);
        $this->act($this->anna, $id, 'nope')->assertStatus(422)->assertJsonPath('code', 'unknown_action');

        // 422 från valideringen av själva anropet.
        $this->as($this->anna)->postJson(self::BASE."/{$id}/actions", ['type' => 'guess'])
            ->assertStatus(422)->assertJsonValidationErrors(['payload', 'version']);

        // 403 ruleset_not_allowed i ett spel som inte får Reveal.
        Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
        $this->as($this->anna)->postJson('/api/v1/games/tocco/matches', ['ruleset' => 'reveal'])
            ->assertStatus(403)->assertJsonPath('code', 'ruleset_not_allowed')->assertJsonStructure(['message', 'code']);

        // 410 invite_expired för okänd token, 409 own_match för egen match.
        $this->as($this->bo)->postJson(self::BASE.'/join', ['token' => str_repeat('a', 43)])
            ->assertStatus(410)->assertJsonPath('code', 'invite_expired');
        $own = $this->createMatch();
        $this->as($this->anna)->postJson(self::BASE.'/join', ['token' => $own['token']])
            ->assertStatus(409)->assertJsonPath('code', 'own_match');

        // 429 från gränsen (match-join 10/min per användare) har code rate_limited.
        $carl = User::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->as($carl)->postJson(self::BASE.'/join', ['token' => 'x'])->assertStatus(410);
        }
        $this->as($carl)->postJson(self::BASE.'/join', ['token' => 'x'])
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    }

    public function test_too_many_active_is_distinct_from_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createMatch();
        }

        $this->as($this->anna)->postJson(self::BASE, ['ruleset' => 'reveal'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_active');
    }

    public function test_since_filters_events(): void
    {
        $id = $this->startedMatch();
        $this->act($this->anna, $id, 'set_secret', ['value' => 10])->assertOk();
        $this->act($this->bo, $id, 'set_secret', ['value' => 20])->assertOk();

        $all = $this->poll($this->anna, $id)->assertOk();
        $this->assertSame([1, 2, 3, 4], array_column($all->json('events'), 'seq'));

        $since = $this->poll($this->anna, $id, [], '?since=2')->assertOk();
        $this->assertSame([3, 4], array_column($since->json('events'), 'seq'));

        $action = $this->as($this->anna)->postJson(self::BASE."/{$id}/actions?since=4", [
            'type' => 'guess', 'payload' => ['value' => 30], 'version' => $this->version($id),
        ])->assertOk();
        $this->assertSame([5], array_column($action->json('events'), 'seq'));

        foreach (['-1', 'abc', '1.5'] as $bad) {
            $this->poll($this->anna, $id, [], '?since='.$bad)->assertStatus(422)->assertJsonValidationErrors(['since']);
        }
    }

    public function test_missing_foreign_and_wrong_game_matches_look_identical(): void
    {
        $id = $this->startedMatch();
        $stranger = User::factory()->create();
        $other = Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true]);

        $responses = [
            'stranger' => $this->poll($stranger, $id),
            'missing' => $this->poll($this->anna, strtolower((string) Str::ulid())),
            'wrong game' => $this->as($this->anna)->getJson("/api/v1/games/{$other->slug}/matches/{$id}"),
            'stranger acts' => $this->act($stranger, $id, 'set_secret', ['value' => 10]),
            'stranger leaves' => $this->as($stranger)->postJson(self::BASE."/{$id}/leave"),
            'stranger rematch' => $this->as($stranger)->postJson(self::BASE."/{$id}/rematch"),
        ];

        $expected = $responses['missing']->assertStatus(404)->getContent();
        foreach ($responses as $case => $response) {
            $this->assertSame(404, $response->status(), $case);
            $this->assertSame($expected, $response->getContent(), $case);
        }

        $this->assertSame(GameMatch::STATUS_ACTIVE, GameMatch::findOrFail($id)->status);
        $this->as($stranger)->getJson(self::BASE.'/active')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/active')->assertUnauthorized();
        $this->postJson(self::BASE, ['ruleset' => 'reveal'])->assertUnauthorized();
    }
}
