<?php

namespace Tests\Feature\Matches;

use App\Matches\MatchContext;
use App\Matches\RulesetRegistry;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\AssertsNoMatchLeak;
use Tests\TestCase;

/**
 * Invarianter över hela matchgrunden, körda över HTTP:
 * inget dolt tal når fel säte, händelseloggen spelar upp till lagrad state,
 * och en match man inte sitter i ser likadan ut som en som inte finns.
 */
class MatchInvariantTest extends TestCase
{
    use AssertsNoMatchLeak;
    use RefreshDatabase;

    private const BASE = '/api/v1/games/reveal/matches';

    private Game $game;

    /** @var array{0: User, 1: User} */
    private array $users;

    /** Det egna talet som `you` ska visa, per säte. */
    private array $own = [null, null];

    /** Värden som gissats i aktuell runda, av någon av spelarna. */
    private array $guessed = [];

    private int $readies = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'reveal', 'name' => 'Reveal', 'is_active' => true]);
        $this->users = [User::factory()->create(), User::factory()->create()];
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** Kontrollerar ett svar till ett säte mot testets bild av vem som vet vad. */
    private function check(TestResponse $response, int $seat, string $what): array
    {
        $response->assertOk();

        return $this->checkView($response->json(), $seat, $what);
    }

    private function checkView(array $view, int $seat, string $what): array
    {
        $this->assertSame($seat, $view['your_seat'], $what);

        try {
            $this->assertNoLeak($view, $this->own[1 - $seat], $this->own[$seat], $this->guessed);
        } catch (AssertionFailedError $e) {
            $this->fail("{$what}, seat {$seat}: ".$e->getMessage());
        }

        return $view;
    }

    private function poll(int $seat, string $id): TestResponse
    {
        return $this->as($this->users[$seat])->getJson(self::BASE."/{$id}");
    }

    /** Ett drag över HTTP. Kontrollerar draget hos den som gjorde det och en poll hos den andra. */
    private function step(string $id, int $seat, string $type, array $payload = [], bool $ends = false): array
    {
        if ($type === 'set_secret') {
            $this->own[$seat] = $payload['value'];
        } elseif ($type === 'guess') {
            $this->guessed[] = $payload['value'];
        } elseif ($type === 'ready' && ++$this->readies === 2) {
            // Båda redo: ny runda, dolda tal nollställs.
            $this->readies = 0;
            $this->own = [null, null];
            $this->guessed = [];
        }
        if ($ends) {
            // Matchen avslutas och dold state rensas.
            $this->own = [null, null];
        }

        $response = $this->as($this->users[$seat])->postJson(self::BASE."/{$id}/actions", [
            'type' => $type,
            'payload' => $payload,
            'version' => GameMatch::findOrFail($id)->version,
        ]);
        $what = "{$type} ".json_encode($payload);
        $view = $this->check($response, $seat, $what);
        $this->check($this->poll(1 - $seat, $id), 1 - $seat, "poll after {$what}");

        return $view;
    }

    /**
     * Spelar en hel Reveal-match över HTTP som säte 1 vinner 2–1, med en oavgjord första runda.
     * Talen är små med flit, så att de krockar med seq, version, round och säte.
     */
    private function playMatch(): string
    {
        $created = $this->as($this->users[0])->postJson(self::BASE, ['ruleset' => 'reveal', 'name' => 'Anna'])->assertCreated();
        $this->checkView($created->json('match'), 0, 'create');
        $id = $created->json('match.id');

        $joined = $this->as($this->users[1])->postJson(self::BASE.'/join', ['token' => $created->json('join_token'), 'name' => 'Bo']);
        $this->check($joined, 1, 'join');
        $this->check($this->poll(0, $id), 0, 'poll after join');

        // Runda 1, säte 0 startar: båda träffar, oavgjort.
        $this->step($id, 0, 'set_secret', ['value' => 1]);
        $this->step($id, 1, 'set_secret', ['value' => 2]);
        $this->step($id, 0, 'guess', ['value' => 50]);
        $this->step($id, 1, 'guess', ['value' => 60]);
        $this->step($id, 0, 'guess', ['value' => 2]);
        $this->step($id, 1, 'guess', ['value' => 1]);
        $this->step($id, 0, 'ready');
        $this->step($id, 1, 'ready');

        // Runda 2, säte 1 startar och missar, säte 0 träffar: 1–0.
        $this->step($id, 0, 'set_secret', ['value' => 2]);
        $this->step($id, 1, 'set_secret', ['value' => 3]);
        $this->step($id, 1, 'guess', ['value' => 1]);
        $this->step($id, 0, 'guess', ['value' => 3]);
        $this->step($id, 1, 'ready');
        $this->step($id, 0, 'ready');

        // Runda 3, säte 0 startar och missar, säte 1 träffar: 1–1.
        $this->step($id, 0, 'set_secret', ['value' => 3]);
        $this->step($id, 1, 'set_secret', ['value' => 1]);
        $this->step($id, 0, 'guess', ['value' => 2]);
        $this->step($id, 1, 'guess', ['value' => 3]);
        $this->step($id, 0, 'ready');
        $this->step($id, 1, 'ready');

        // Runda 4, säte 1 startar och träffar, säte 0 missar sista chansen: 1–2.
        $this->step($id, 0, 'set_secret', ['value' => 4]);
        $this->step($id, 1, 'set_secret', ['value' => 2]);
        $this->step($id, 1, 'guess', ['value' => 4]);
        $end = $this->step($id, 0, 'guess', ['value' => 5], ends: true);

        $this->assertSame(GameMatch::STATUS_FINISHED, $end['status']);
        $this->assertSame([1, 2], $end['state']['score']);
        $this->assertSame(1, $end['state']['winner']);
        $roundEnds = array_values(array_filter($end['events'], fn ($e) => $e['type'] === 'round_end'));
        $this->assertSame([null, 0, 1, 1], array_column(array_column($roundEnds, 'payload'), 'winner'));
        $this->assertSame([[1, 2], [2, 3], [3, 1], [4, 2]], array_column(array_column($roundEnds, 'payload'), 'secrets'));

        return $id;
    }

    public function test_no_hidden_state_leaks(): void
    {
        $id = $this->playMatch();

        // Efter matchen: båda ser sin vy utan dold state.
        $this->check($this->poll(0, $id), 0, 'final poll');
        $this->check($this->poll(1, $id), 1, 'final poll');
    }

    public function test_leak_scanner_catches_planted_leaks(): void
    {
        $base = [
            'id' => '01abc', 'version' => 7, 'your_seat' => 0,
            'players' => [['seat' => 0, 'name' => 'A', 'connected' => true]],
            'state' => ['round' => 7, 'score' => [7, 0], 'guesses' => [[['value' => 3, 'result' => 'higher']], []]],
            'you' => ['secret' => 5],
            'events' => [
                ['seq' => 7, 'type' => 'round_begin', 'seat' => null, 'payload' => ['round' => 7, 'starter' => 0]],
                ['seq' => 8, 'type' => 'locked', 'seat' => 1, 'payload' => []],
            ],
        ];

        // Strukturella fält med samma värde som talet ger inte larm.
        $this->assertNoLeak($base, 7, 5, [3]);
        // Ett gissat värde får stå i state och i gissningshändelsen.
        $guessed = $base;
        $guessed['state']['guesses'][0][] = ['value' => 7, 'result' => 'correct'];
        $guessed['events'][] = ['seq' => 9, 'type' => 'guess', 'seat' => 0, 'payload' => ['value' => 7, 'result' => 'correct']];
        $this->assertNoLeak($guessed, 7, 5, [3, 7]);

        $planted = [
            'ungissat värde i state' => fn (array &$v) => $v['state']['guesses'][1][] = ['value' => 7, 'result' => 'lower'],
            'ungissat värde i händelse' => fn (array &$v) => $v['events'][] = ['seq' => 9, 'type' => 'guess', 'seat' => 0, 'payload' => ['value' => 7, 'result' => 'lower']],
            'value i fel händelse' => fn (array &$v) => $v['events'][1]['payload']['value'] = 7,
            'okänt fält i state' => fn (array &$v) => $v['state']['target'] = 7,
            'okänt fält i players' => fn (array &$v) => $v['players'][0]['n'] = 7,
            'secrets utanför round_end' => fn (array &$v) => $v['events'][1]['payload']['secrets'] = [null, 1],
            'hidden_state' => fn (array &$v) => $v['players'][0]['hidden_state'] = null,
            'hidden' => fn (array &$v) => $v['state']['hidden'] = [],
            'motspelarens tal i you' => fn (array &$v) => $v['you']['opponent'] = 7,
        ];
        foreach ($planted as $case => $plant) {
            $view = $base;
            $plant($view);
            try {
                $this->assertNoLeak($view, 7, 5, [3]);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail("scanner missed planted leak: {$case}");
        }
    }

    public function test_event_log_replays_to_state(): void
    {
        $id = $this->playMatch();
        // Revansch lägger grundens egna händelser i loggen; de ska hoppas över vid uppspelning.
        $this->as($this->users[0])->postJson(self::BASE."/{$id}/rematch")->assertOk();
        $this->as($this->users[1])->postJson(self::BASE."/{$id}/rematch")->assertOk();

        $match = GameMatch::findOrFail($id);
        $events = GameMatchEvent::where('match_id', $id)->orderBy('seq')->get();
        $this->assertSame(range(1, $events->count()), $events->pluck('seq')->all());
        $this->assertContains('rematch', $events->pluck('type')->all());

        $rules = RulesetRegistry::for($match->game, $match->ruleset);
        $state = $rules->initialState($match->options);
        foreach ($events as $event) {
            if (in_array($event->type, MatchContext::RESERVED_TYPES, true)) {
                continue;
            }
            $state = $rules->apply($state, $event->type, $event->seat, $event->payload ?? []);
        }

        $this->assertSame($match->state, $state);
    }

    public function test_access(): void
    {
        $created = $this->as($this->users[0])->postJson(self::BASE, ['ruleset' => 'reveal'])->assertCreated();
        $id = $created->json('match.id');
        $this->as($this->users[1])->postJson(self::BASE.'/join', ['token' => $created->json('join_token')])->assertOk();

        $stranger = User::factory()->create();
        Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true]);

        $hit = function (User $user, string $base, string $matchId): array {
            $this->as($user);

            return [
                'show' => $this->getJson("{$base}/{$matchId}"),
                'actions' => $this->postJson("{$base}/{$matchId}/actions", ['type' => 'set_secret', 'payload' => ['value' => 10], 'version' => GameMatch::find($matchId)?->version ?? 1]),
                'leave' => $this->postJson("{$base}/{$matchId}/leave"),
                'rematch' => $this->postJson("{$base}/{$matchId}/rematch"),
            ];
        };

        $expected = $this->as($this->users[0])->getJson(self::BASE.'/'.strtolower((string) Str::ulid()))->assertNotFound()->getContent();
        $before = GameMatch::findOrFail($id)->only(['status', 'version', 'state']);
        $eventsBefore = GameMatchEvent::where('match_id', $id)->count();

        $cases = [
            'stranger' => $hit($stranger, self::BASE, $id),
            'member, other game' => $hit($this->users[0], '/api/v1/games/annat/matches', $id),
        ];
        foreach ($cases as $who => $responses) {
            foreach ($responses as $route => $response) {
                $this->assertSame(404, $response->status(), "{$who} {$route}");
                $this->assertSame($expected, $response->getContent(), "{$who} {$route}");
            }
        }
        // Inget av de avvisade anropen rörde matchen.
        $this->assertSame($before, GameMatch::findOrFail($id)->only(['status', 'version', 'state']));
        $this->assertSame($eventsBefore, GameMatchEvent::where('match_id', $id)->count());

        // Gallrad: medlemmen lämnar, matchen ligger orörd i 8 dagar och gallras.
        $this->as($this->users[0])->postJson(self::BASE."/{$id}/leave")->assertOk();
        $this->travel(8)->days();
        $this->artisan('matches:prune')->assertSuccessful();
        $this->assertNull(GameMatch::find($id));

        foreach ([0, 1] as $seat) {
            foreach ($hit($this->users[$seat], self::BASE, $id) as $route => $response) {
                $this->assertSame(404, $response->status(), "pruned, seat {$seat} {$route}");
                $this->assertSame($expected, $response->getContent(), "pruned, seat {$seat} {$route}");
            }
        }
    }
}
