<?php

namespace Tests\Feature\Matches;

use App\Matches\MatchContext;
use App\Matches\RulesetRegistry;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** Matchens läge (options.mode); i echo bär varje gissning ett eko mot gissarens eget tal. */
    private string $mode = 'duel';

    /** Förväntad state.guesses i aktuell runda, per säte. */
    private array $expectedGuesses = [[], []];

    /** Högsta seq varje säte har sett, för polls med since. */
    private array $lastSeq = [0, 0];

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
        if ($view['events'] !== []) {
            $this->lastSeq[$seat] = max($this->lastSeq[$seat], ...array_column($view['events'], 'seq'));
        }

        return $view;
    }

    /** Ett felsvar till ett säte: rätt status och kod, och inget dolt i kroppen. */
    private function checkError(TestResponse $response, int $seat, int $status, string $code, string $what): void
    {
        $response->assertStatus($status);
        $this->assertSame($code, $response->json('code'), $what);
        try {
            $this->assertNoLeakInError($response->json(), $this->own[1 - $seat]);
        } catch (AssertionFailedError $e) {
            $this->fail("{$what}, seat {$seat}: ".$e->getMessage());
        }
    }

    private function action(string $id, int $seat, string $type, array $payload, ?int $version = null): TestResponse
    {
        return $this->as($this->users[$seat])->postJson(self::BASE."/{$id}/actions", [
            'type' => $type,
            'payload' => $payload,
            'version' => $version ?? GameMatch::findOrFail($id)->version,
        ]);
    }

    /**
     * Felvägarna under gissningsfasen, från båda sätena. Inget av anropen får ändra matchen
     * och ingen felkropp får bära motspelarens tal.
     */
    private function probeErrors(string $id, int $onTurn): void
    {
        $off = 1 - $onTurn;
        $version = GameMatch::findOrFail($id)->version;
        $events = GameMatchEvent::where('match_id', $id)->count();

        $this->checkError($this->action($id, $off, 'guess', ['value' => 50]), $off, 422, 'not_your_turn', 'out of turn');
        foreach ([$onTurn, $off] as $seat) {
            $this->checkError($this->action($id, $seat, 'guess', ['value' => 50], $version - 1), $seat, 409, 'stale', 'stale');
            $this->checkError($this->action($id, $seat, 'set_secret', ['value' => 10]), $seat, 422, 'wrong_phase', 'set_secret while guessing');
        }
        foreach ([0, 101] as $value) {
            $this->checkError($this->action($id, $onTurn, 'guess', ['value' => $value]), $onTurn, 422, 'out_of_range', "guess {$value}");
        }
        foreach (['2', 2.5, null, []] as $value) {
            $this->checkError($this->action($id, $onTurn, 'guess', ['value' => $value]), $onTurn, 422, 'invalid_payload', 'guess '.json_encode($value));
        }

        $this->assertSame($version, GameMatch::findOrFail($id)->version);
        $this->assertSame($events, GameMatchEvent::where('match_id', $id)->count());
    }

    /** Nollställer testets modell inför en ny match (revansch). */
    private function resetModel(): void
    {
        $this->own = [null, null];
        $this->guessed = [];
        $this->readies = 0;
        $this->expectedGuesses = [[], []];
        $this->lastSeq = [0, 0];
        $this->mode = 'duel';
    }

    private function poll(int $seat, string $id, string $query = ''): TestResponse
    {
        return $this->as($this->users[$seat])->getJson(self::BASE."/{$id}{$query}");
    }

    /** Ett drag över HTTP. Kontrollerar draget hos den som gjorde det och en poll hos den andra. */
    private function step(string $id, int $seat, string $type, array $payload = [], bool $ends = false): array
    {
        if ($type === 'set_secret') {
            $this->own[$seat] = $payload['value'];
        } elseif ($type === 'guess') {
            $this->guessed[] = $payload['value'];
            $secret = $this->own[1 - $seat];
            $guess = [
                'value' => $payload['value'],
                'result' => $secret === $payload['value'] ? 'correct' : ($secret > $payload['value'] ? 'higher' : 'lower'),
            ];
            if ($this->mode === 'echo') {
                $mine = $this->own[$seat];
                $guess['echo'] = $mine === $payload['value'] ? 'self' : ($mine > $payload['value'] ? 'higher' : 'lower');
            }
            $this->expectedGuesses[$seat][] = $guess;
        } elseif ($type === 'ready' && ++$this->readies === 2) {
            // Båda redo: ny runda, dolda tal nollställs.
            $this->readies = 0;
            $this->own = [null, null];
            $this->guessed = [];
            $this->expectedGuesses = [[], []];
        }
        if ($ends) {
            // Matchen avslutas och dold state rensas.
            $this->own = [null, null];
        }

        $other = 1 - $seat;
        $what = "{$type} ".json_encode($payload);
        $view = $this->check($this->action($id, $seat, $type, $payload), $seat, $what);
        $this->assertSame($this->expectedGuesses, $view['state']['guesses'], "guesses after {$what}");
        $this->assertSame($this->mode, $view['state']['mode'], "mode after {$what}");
        if ($type === 'guess') {
            $event = collect($view['events'])->last(fn ($e) => $e['type'] === 'guess');
            $this->assertSame(end($this->expectedGuesses[$seat]), $event['payload'], "guess payload after {$what}");
        }

        // Motspelaren pollar först med since (bara nya händelser), sedan hela vyn.
        $since = $this->lastSeq[$other];
        $delta = $this->check($this->poll($other, $id, "?since={$since}"), $other, "since={$since} poll after {$what}");
        $seqs = array_column($delta['events'], 'seq');
        $this->assertNotEmpty($seqs, "since poll after {$what}");
        $this->assertSame(range($since + 1, $since + count($seqs)), $seqs, "since poll after {$what}");
        $this->assertSame($this->expectedGuesses, $delta['state']['guesses'], "since poll guesses after {$what}");

        $full = $this->check($this->poll($other, $id), $other, "poll after {$what}");
        $this->assertSame($this->expectedGuesses, $full['state']['guesses'], "poll guesses after {$what}");

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
        $this->probeErrors($id, 0);
        $this->step($id, 0, 'guess', ['value' => 50]);
        $this->probeErrors($id, 1);
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

        // Revansch: båda svaren kontrolleras.
        $this->check($this->as($this->users[0])->postJson(self::BASE."/{$id}/rematch"), 0, 'rematch request');
        $both = $this->check($this->as($this->users[1])->postJson(self::BASE."/{$id}/rematch"), 1, 'rematch');
        $newId = collect($both['events'])->firstWhere('type', 'rematch')['payload']['match_id'];

        // I revanschen lämnar säte 1 mitt i en runda: varken lämnarens svar eller den
        // kvarvarandes poll får visa motspelarens tal, och you är tomt för båda.
        $this->resetModel();
        $this->check($this->poll(0, $newId), 0, 'rematch poll');
        $this->step($newId, 0, 'set_secret', ['value' => 7]);
        $this->step($newId, 1, 'set_secret', ['value' => 8]);
        $this->step($newId, 0, 'guess', ['value' => 1]);
        $secrets = $this->own;
        $this->own = [null, null];

        $left = $this->as($this->users[1])->postJson(self::BASE."/{$newId}/leave")->assertOk()->json();
        $this->assertSame(GameMatch::STATUS_ABANDONED, $left['status']);
        $this->assertSame('left', end($left['events'])['type']);
        $this->assertNoLeak($left, $secrets[0], null, $this->guessed);
        $remaining = $this->poll(0, $newId)->assertOk()->json();
        $this->assertSame(GameMatch::STATUS_ABANDONED, $remaining['status']);
        $this->assertNoLeak($remaining, $secrets[1], null, $this->guessed);
    }

    /**
     * En hel match i ekoläget över HTTP, med ett self-eko i varje runda och tal som krockar
     * med seq, version och round. Inget numeriskt dolt tal får synas före round_end.
     */
    public function test_no_hidden_state_leaks_in_echo_mode(): void
    {
        $this->mode = 'echo';
        $created = $this->as($this->users[0])->postJson(self::BASE, ['ruleset' => 'reveal', 'name' => 'Anna', 'options' => ['mode' => 'echo']])->assertCreated();
        $this->assertSame('echo', $created->json('match.state.mode'));
        $this->checkView($created->json('match'), 0, 'create');
        $id = $created->json('match.id');
        $this->assertSame('echo', GameMatch::findOrFail($id)->options['mode']);

        $joined = $this->check($this->as($this->users[1])->postJson(self::BASE.'/join', ['token' => $created->json('join_token'), 'name' => 'Bo']), 1, 'join');
        $this->assertSame('echo', $joined['state']['mode']);

        // Runda 1, säte 0 startar. Säte 0 gissar sitt eget tal (self), säte 1 träffar: 0–1.
        $this->step($id, 0, 'set_secret', ['value' => 3]);
        $this->step($id, 1, 'set_secret', ['value' => 5]);
        $this->probeErrors($id, 0);
        $this->step($id, 0, 'guess', ['value' => 9]);
        $this->step($id, 1, 'guess', ['value' => 1]);
        $this->step($id, 0, 'guess', ['value' => 3]);
        $this->step($id, 1, 'guess', ['value' => 3]);
        $this->step($id, 1, 'ready');
        $this->step($id, 0, 'ready');

        // Runda 2, säte 1 startar med self-eko, säte 0 träffar: 1–1.
        $this->step($id, 0, 'set_secret', ['value' => 2]);
        $this->step($id, 1, 'set_secret', ['value' => 4]);
        $this->step($id, 1, 'guess', ['value' => 4]);
        $this->step($id, 0, 'guess', ['value' => 1]);
        $this->step($id, 1, 'guess', ['value' => 7]);
        $this->step($id, 0, 'guess', ['value' => 4]);
        $this->step($id, 0, 'ready');
        $this->step($id, 1, 'ready');

        // Runda 3, säte 0 startar och träffar, säte 1 missar sista chansen: 2–1.
        $this->step($id, 0, 'set_secret', ['value' => 6]);
        $this->step($id, 1, 'set_secret', ['value' => 8]);
        $this->step($id, 0, 'guess', ['value' => 8]);
        $end = $this->step($id, 1, 'guess', ['value' => 8], ends: true);

        $this->assertSame(GameMatch::STATUS_FINISHED, $end['status']);
        $this->assertSame([2, 1], $end['state']['score']);
        $echoes = array_column(array_column(array_filter($end['events'], fn ($e) => $e['type'] === 'guess'), 'payload'), 'echo');
        $this->assertSame(['lower', 'higher', 'self', 'higher', 'self', 'higher', 'lower', 'lower', 'lower', 'self'], $echoes);
        $this->check($this->poll(0, $id), 0, 'final poll');
        $this->check($this->poll(1, $id), 1, 'final poll');

        // Revanschen behåller läget.
        $this->check($this->as($this->users[0])->postJson(self::BASE."/{$id}/rematch"), 0, 'rematch request');
        $both = $this->check($this->as($this->users[1])->postJson(self::BASE."/{$id}/rematch"), 1, 'rematch');
        $newId = collect($both['events'])->firstWhere('type', 'rematch')['payload']['match_id'];
        $this->assertSame('echo', GameMatch::findOrFail($newId)->state['mode']);
    }

    /**
     * Matcher skapade före läget har varken options.mode eller state.mode. Vyn visar dem som
     * ren duell, gissningar får inget eko och uppspelningen stämmer.
     */
    public function test_legacy_match_without_mode(): void
    {
        $created = $this->as($this->users[0])->postJson(self::BASE, ['ruleset' => 'reveal'])->assertCreated();
        $id = $created->json('match.id');
        $this->as($this->users[1])->postJson(self::BASE.'/join', ['token' => $created->json('join_token')])->assertOk();
        $this->step($id, 0, 'set_secret', ['value' => 1]);

        $match = GameMatch::findOrFail($id);
        $options = $match->options;
        $state = $match->state;
        unset($options['mode'], $state['mode']);
        DB::table('matches')->where('id', $id)->update(['options' => json_encode($options), 'state' => json_encode($state)]);
        $this->assertArrayNotHasKey('mode', GameMatch::findOrFail($id)->state);

        $this->check($this->poll(0, $id), 0, 'legacy poll');
        $this->step($id, 1, 'set_secret', ['value' => 2]);
        $view = $this->step($id, 0, 'guess', ['value' => 2]);
        $this->assertSame(['value' => 2, 'result' => 'correct'], collect($view['events'])->last(fn ($e) => $e['type'] === 'guess')['payload']);
        $this->assertArrayNotHasKey('mode', GameMatch::findOrFail($id)->state, 'stored legacy state is not rewritten');

        $this->artisan('matches:check-invariants')->assertExitCode(0);
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
        // Ekot är ett ord och får stå i gissningar och gissningshändelser.
        $echoed = $base;
        foreach (['higher', 'lower', 'self'] as $i => $word) {
            $echoed['state']['guesses'][0][] = ['value' => 3, 'result' => 'higher', 'echo' => $word];
            $echoed['events'][] = ['seq' => 9 + $i, 'type' => 'guess', 'seat' => 0, 'payload' => ['value' => 3, 'result' => 'higher', 'echo' => $word]];
        }
        $echoed['state']['mode'] = 'echo';
        $this->assertNoLeak($echoed, 7, 5, [3]);

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
            'numerisk sträng' => fn (array &$v) => $v['players'][0]['n'] = '7',
            'flyttal' => fn (array &$v) => $v['state']['x'] = 7.0,
            'tal som eko i händelse' => fn (array &$v) => $v['events'][] = ['seq' => 9, 'type' => 'guess', 'seat' => 0, 'payload' => ['value' => 3, 'result' => 'higher', 'echo' => 7]],
            'numerisk sträng som eko i händelse' => fn (array &$v) => $v['events'][] = ['seq' => 9, 'type' => 'guess', 'seat' => 0, 'payload' => ['value' => 3, 'result' => 'higher', 'echo' => '7']],
            'tal som eko i state' => fn (array &$v) => $v['state']['guesses'][0][0]['echo'] = 7,
            'flyttal som gissningsvärde' => fn (array &$v) => $v['state']['guesses'][1][] = ['value' => 7.0, 'result' => 'lower'],
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

        // Felsvar: version är strukturell, allt annat som bär talet är en läcka.
        $error = ['message' => 'Matchen har ändrats.', 'code' => 'stale', 'version' => 7];
        $this->assertNoLeakInError($error, 7);
        foreach ([
            'value' => $error + ['value' => 7],
            'numerisk sträng' => $error + ['detail' => '7'],
            'flyttal' => $error + ['detail' => 7.0],
            'hidden' => $error + ['hidden' => ['secret' => 1]],
            'you' => $error + ['you' => ['secret' => 1]],
            'secrets' => $error + ['secrets' => [1, 2]],
        ] as $case => $body) {
            try {
                $this->assertNoLeakInError($body, 7);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail("error scanner missed planted leak: {$case}");
        }
    }

    public function test_event_log_replays_to_state(): void
    {
        $id = $this->playMatch();
        // Revansch lägger grundens egna händelser i loggen; de ska hoppas över vid uppspelning.
        $this->check($this->as($this->users[0])->postJson(self::BASE."/{$id}/rematch"), 0, 'rematch request');
        $this->check($this->as($this->users[1])->postJson(self::BASE."/{$id}/rematch"), 1, 'rematch');

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

        $this->assertSame($this->canonical($match->state), $this->canonical($state));
    }

    /** MySQL:s JSON-kolumn ordnar om objektnycklar; listor behåller ordningen. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => $this->canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
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
                'invite' => $this->postJson("{$base}/{$matchId}/invite"),
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
        $this->check($this->as($this->users[0])->postJson(self::BASE."/{$id}/leave"), 0, 'leave');
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
