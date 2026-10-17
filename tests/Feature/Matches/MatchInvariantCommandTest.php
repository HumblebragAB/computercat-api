<?php

namespace Tests\Feature\Matches;

use App\Matches\MatchService;
use App\Matches\Rules\RevealRules;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\GameMatchPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MatchInvariantCommandTest extends TestCase
{
    use RefreshDatabase;

    private MatchService $service;

    private Game $game;

    private User $anna;

    private User $bo;

    protected function setUp(): void
    {
        parent::setUp();
        // Lottningen av startspelare är slump i produktion; testerna förutsätter säte 0.
        app()->instance(RevealRules::STARTER_DRAW_BINDING, fn (): int => 0);
        $this->service = app(MatchService::class);
        $this->game = Game::create(['slug' => 'reveal', 'name' => 'Reveal', 'is_active' => true]);
        $this->anna = User::factory()->create();
        $this->bo = User::factory()->create();
    }

    private function active(): GameMatch
    {
        $created = $this->service->create($this->game, $this->anna, 'reveal', [], 'Anna');

        return $this->service->join($this->game, $this->bo, $created['token'], 'Bo');
    }

    /** Spelar en match med hemliga tal och en gissning, så att state och hidden_state är nollskilda. */
    private function played(): GameMatch
    {
        $match = $this->active();
        $this->service->act($match->fresh(), $this->anna, 'set_secret', ['value' => 73], $match->fresh()->version);
        $this->service->act($match->fresh(), $this->bo, 'set_secret', ['value' => 41], $match->fresh()->version);

        return $match->fresh();
    }

    public function test_clean_match_passes(): void
    {
        $this->played();
        $this->artisan('matches:check-invariants')->expectsOutputToContain('1')->assertExitCode(0);
    }

    public function test_echo_match_passes(): void
    {
        $created = $this->service->create($this->game, $this->anna, 'reveal', ['mode' => 'echo'], 'Anna');
        $match = $this->service->join($this->game, $this->bo, $created['token'], 'Bo');
        $this->service->act($match->fresh(), $this->anna, 'set_secret', ['value' => 73], $match->fresh()->version);
        $this->service->act($match->fresh(), $this->bo, 'set_secret', ['value' => 41], $match->fresh()->version);
        $this->service->act($match->fresh(), $this->anna, 'guess', ['value' => 73], $match->fresh()->version);
        $this->assertSame('self', GameMatchEvent::where('match_id', $match->id)->where('type', 'guess')->first()->payload['echo']);

        $this->artisan('matches:check-invariants')->expectsOutputToContain('1')->assertExitCode(0);
    }

    public function test_seq_gap_fails(): void
    {
        $match = $this->played();
        $middle = GameMatchEvent::where('match_id', $match->id)->orderBy('seq')->get()->get(1);
        $this->assertNotNull($middle);
        $middle->delete();

        Log::spy();
        $this->artisan('matches:check-invariants')->expectsOutputToContain($match->id)->assertExitCode(1);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_tampered_state_fails(): void
    {
        $match = $this->played();
        $state = $match->state;
        $state['score'] = [5, 0];
        DB::table('matches')->where('id', $match->id)->update(['state' => json_encode($state)]);

        $this->artisan('matches:check-invariants')->expectsOutputToContain($match->id)->assertExitCode(1);
    }

    public function test_finished_match_with_hidden_state_fails(): void
    {
        $match = $this->played();
        $this->assertNotNull(GameMatchPlayer::where('match_id', $match->id)->where('seat', 0)->first()->hidden_state);
        DB::table('matches')->where('id', $match->id)->update(['status' => GameMatch::STATUS_FINISHED]);

        $this->artisan('matches:check-invariants')->expectsOutputToContain($match->id)->assertExitCode(1);
    }

    public function test_unknown_ruleset_is_a_violation(): void
    {
        $match = $this->played();
        DB::table('matches')->where('id', $match->id)->update(['ruleset' => 'finns-inte']);

        $this->artisan('matches:check-invariants')->expectsOutputToContain($match->id)->assertExitCode(1);
    }

    public function test_old_matches_are_not_checked(): void
    {
        $match = $this->played();
        GameMatchEvent::where('match_id', $match->id)->orderBy('seq')->first()->delete();
        DB::table('matches')->where('id', $match->id)->update(['updated_at' => now()->subDays(2)]);

        $this->artisan('matches:check-invariants')->assertExitCode(0);
    }

    public function test_output_and_log_contain_no_secret_values(): void
    {
        $match = $this->played();
        $state = $match->state;
        $state['score'] = [9, 9];
        DB::table('matches')->where('id', $match->id)->update(['state' => json_encode($state), 'status' => GameMatch::STATUS_FINISHED]);

        Log::spy();
        Artisan::call('matches:check-invariants');
        $output = Artisan::output();

        $this->assertStringContainsString($match->id, $output);
        // ULID:er kan innehålla siffror; ta bort id:t innan hemliga värden letas efter.
        $rest = str_replace($match->id, '', $output);
        foreach (['73', '41', '9, 9', '9,9'] as $secret) {
            $this->assertStringNotContainsString($secret, $rest);
        }
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context = []) use ($match) {
            $dump = str_replace($match->id, '', $message.json_encode($context));

            return str_contains($message.json_encode($context), $match->id)
                && ! str_contains($dump, '73') && ! str_contains($dump, '41') && ! str_contains($dump, '9,9');
        })->once();
    }
}
