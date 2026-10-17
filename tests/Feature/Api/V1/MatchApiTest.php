<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Matches\FakeRules;

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
        $this->assertMatchesRegularExpression('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}\z/', $created->json('join_token'));
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

    public function test_empty_payload_and_you_serialize_as_json_objects(): void
    {
        $id = $this->startedMatch();

        $this->as($this->bo)->postJson(self::BASE."/{$id}/leave")->assertOk();

        $raw = $this->poll($this->anna, $id)->assertOk()->getContent();
        $this->assertStringContainsString('"type":"left"', $raw);
        $this->assertStringContainsString('"payload":{}', $raw);
        $this->assertStringNotContainsString('"payload":[]', $raw);

        config(['matches.rulesets.reveal.class' => FakeRules::class]);
        $started = $this->startedMatch();
        $rawStarted = $this->poll($this->anna, $started)->assertOk()->getContent();
        $this->assertStringContainsString('"you":{}', $rawStarted);
        $this->assertStringNotContainsString('"you":[]', $rawStarted);
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
            'stranger invite' => $this->as($stranger)->postJson(self::BASE."/{$id}/invite"),
        ];

        $expected = $responses['missing']->assertStatus(404)->getContent();
        foreach ($responses as $case => $response) {
            $this->assertSame(404, $response->status(), $case);
            $this->assertSame($expected, $response->getContent(), $case);
        }

        $this->assertSame(GameMatch::STATUS_ACTIVE, GameMatch::findOrFail($id)->status);
        $this->as($stranger)->getJson(self::BASE.'/active')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_match_responses_expose_etag_and_retry_after_via_cors(): void
    {
        $this->game->update(['allowed_origins' => ['https://reveal.apps.humblebrag.se']]);
        $id = $this->startedMatch();

        $response = $this->poll($this->anna, $id, ['Origin' => 'https://reveal.apps.humblebrag.se'])->assertOk();

        $response->assertHeader('Access-Control-Allow-Origin', 'https://reveal.apps.humblebrag.se');
        $exposed = array_map('trim', explode(',', strtolower((string) $response->headers->get('Access-Control-Expose-Headers'))));
        $this->assertContains('etag', $exposed);
        $this->assertContains('retry-after', $exposed);
    }

    public function test_post_responses_carry_the_same_etag_as_a_poll(): void
    {
        ['id' => $id, 'token' => $token] = $this->createMatch();

        $joined = $this->as($this->bo)->postJson(self::BASE.'/join', ['token' => $token, 'name' => 'Bo'])->assertOk();
        $this->assertStringStartsWith('"'.$this->version($id).'-', $this->etagOf($joined));

        $this->poll($this->anna, $id)->assertOk();
        $this->poll($this->bo, $id)->assertOk(); // båda anslutna, så ETag är stabil.

        $acted = $this->act($this->anna, $id, 'set_secret', ['value' => 10])->assertOk();
        $etag = $this->etagOf($acted);
        $this->assertSame($etag, $this->etagOf($this->poll($this->anna, $id)->assertOk()));
        $this->poll($this->anna, $id, ['If-None-Match' => $etag])->assertStatus(304);

        $this->etagOf($this->as($this->anna)->postJson(self::BASE."/{$id}/leave")->assertOk());
        GameMatch::whereKey($id)->update(['status' => GameMatch::STATUS_FINISHED]);
        $this->etagOf($this->as($this->anna)->postJson(self::BASE."/{$id}/rematch")->assertOk());

        $fresh = $this->createMatch();
        $this->etagOf($this->as($this->anna)->postJson(self::BASE."/{$fresh['id']}/invite")->assertOk());
    }

    public function test_invite_replaces_the_token(): void
    {
        ['id' => $id, 'token' => $old] = $this->createMatch();
        $before = GameMatch::findOrFail($id);
        $this->travel(10)->minutes();

        $response = $this->as($this->anna)->postJson(self::BASE."/{$id}/invite")->assertOk();
        $this->assertSame(['match', 'join_token'], array_keys($response->json()));
        $new = $response->json('join_token');
        $this->assertMatchesRegularExpression('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}\z/', $new);
        $this->assertNotSame($old, $new);
        $response->assertJsonPath('match.id', $id)->assertJsonPath('match.status', 'waiting')->assertJsonPath('match.your_seat', 0);

        $after = GameMatch::findOrFail($id);
        $this->assertSame(hash_hmac('sha256', $new, hash_hmac('sha256', 'match-invite-code', config('app.key'), true)), $after->join_token_hash);
        $this->assertSame($before->version + 1, $after->version);
        $this->assertTrue($after->expires_at->equalTo(now()->addMinutes(15)->startOfSecond()));
        $this->assertSame(0, $after->events()->count());

        // Tokenet syns aldrig i vyn.
        $view = $this->poll($this->anna, $id)->assertOk()->getContent();
        // Som JSON-värde: fyra tecken kan av en slump stå inuti t.ex. ett id.
        $this->assertStringNotContainsString('"'.$new.'"', $view);
        $this->assertStringNotContainsString('"'.$old.'"', $view);
        $this->assertStringNotContainsString('join_token', $view);

        $this->as($this->bo)->postJson(self::BASE.'/join', ['token' => $old])
            ->assertStatus(410)->assertJsonPath('code', 'invite_expired');
        $this->as($this->bo)->postJson(self::BASE.'/join', ['token' => $new, 'name' => 'Bo'])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('your_seat', 1);
    }

    public function test_invite_only_for_the_creator_while_waiting(): void
    {
        $id = $this->startedMatch();
        $version = $this->version($id);

        $this->as($this->anna)->postJson(self::BASE."/{$id}/invite")
            ->assertStatus(422)->assertJsonPath('code', 'wrong_phase');
        $this->as($this->bo)->postJson(self::BASE."/{$id}/invite")
            ->assertStatus(422)->assertJsonPath('code', 'wrong_phase');
        $this->assertSame($version, $this->version($id));
        $this->assertNull(GameMatch::findOrFail($id)->join_token_hash);

        $stranger = User::factory()->create();
        $waiting = $this->createMatch();
        $missing = $this->as($this->anna)->postJson(self::BASE.'/'.strtolower((string) Str::ulid()).'/invite')->assertStatus(404);
        $this->as($stranger)->postJson(self::BASE."/{$waiting['id']}/invite")
            ->assertStatus(404)->assertExactJson($missing->json());

        // Övergiven match: fel läge, även för skaparen.
        $this->as($this->anna)->postJson(self::BASE."/{$waiting['id']}/leave")->assertOk();
        $this->as($this->anna)->postJson(self::BASE."/{$waiting['id']}/invite")
            ->assertStatus(422)->assertJsonPath('code', 'wrong_phase');
    }

    public function test_poll_loads_game_and_players_once(): void
    {
        $id = $this->startedMatch();
        $this->poll($this->anna, $id)->assertOk();
        $this->travel(6)->seconds(); // så att touch skriver igen

        DB::enableQueryLog();
        $this->poll($this->anna, $id)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $selects = fn (string $table) => count(array_filter($queries, fn ($q) => preg_match('/^select .* from [`"]?'.$table.'[`"]?(\s|$)/i', $q) === 1));
        $this->assertSame(1, $selects('games'), implode("\n", $queries));
        $this->assertSame(1, $selects('match_players'), implode("\n", $queries));
        $this->assertCount(1, array_filter($queries, fn ($q) => preg_match('/^update [`"]?match_players[`"]?\s/i', $q) === 1));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/active')->assertUnauthorized();
        $this->postJson(self::BASE, ['ruleset' => 'reveal'])->assertUnauthorized();
    }
}
