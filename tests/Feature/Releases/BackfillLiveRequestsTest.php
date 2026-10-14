<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\ReleaseSetupStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillLiveRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function log(Game $game, string $tool, string $outcome, array $arguments = []): void
    {
        McpAuditLog::create(['user_id' => null, 'game_id' => $game->id, 'tool' => $tool, 'arguments' => $arguments, 'outcome' => $outcome]);
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_10_16_100005_backfill_release_targets_for_live_requests.php'))->up();
    }

    private function newGame(string $slug, array $extra = []): Game
    {
        return Game::create(array_merge(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true, 'settings' => []], $extra));
    }

    public function test_only_games_with_an_ok_live_request_get_web_and_an_open_address_step(): void
    {
        $asked = $this->newGame('asked');
        $failedAsk = $this->newGame('failedask');
        $otherTool = $this->newGame('othertool');
        $never = $this->newGame('never');
        $this->log($asked, 'request_live', 'ok');
        $this->log($failedAsk, 'request_live', 'validation_error');
        $this->log($failedAsk, 'request_live', 'denied');
        $this->log($otherTool, 'get_game', 'ok');

        $this->migrate();

        $this->assertSame(['web'], $asked->fresh()->releaseTargets());
        $this->assertSame('open', ReleaseSetupStep::where('game_id', $asked->id)->where('key', 'web_public_address')->value('status'));
        foreach ([$failedAsk, $otherTool, $never] as $game) {
            $this->assertSame([], $game->fresh()->releaseTargets(), $game->slug);
            $this->assertSame(0, ReleaseSetupStep::where('game_id', $game->id)->count(), $game->slug);
        }
    }

    public function test_archived_games_are_skipped(): void
    {
        $archived = $this->newGame('archived', ['is_active' => false]);
        $this->log($archived, 'request_live', 'ok');

        $this->migrate();

        $this->assertSame([], $archived->fresh()->releaseTargets());
        $this->assertSame(0, ReleaseSetupStep::count());
    }

    public function test_the_requested_domain_ends_up_in_the_setup_step(): void
    {
        $game = $this->newGame('asked');
        $this->log($game, 'request_live', 'ok', ['game' => 'asked', 'domain' => 'gammal.se']);
        $this->log($game, 'request_live', 'ok', ['game' => 'asked', 'domain' => 'Kattsvep.se']);

        $this->migrate();

        $instruction = ReleaseSetupStep::where('game_id', $game->id)->value('instruction');
        $this->assertStringContainsString('Önskad adress enligt Live-förfrågan: kattsvep.se.', $instruction);
        $this->assertStringNotContainsString('gammal.se', $instruction);
    }

    public function test_an_invalid_logged_domain_is_ignored(): void
    {
        $game = $this->newGame('asked');
        $this->log($game, 'request_live', 'ok', ['domain' => 'https://x.se/path']);
        $this->log($game, 'request_live', 'ok', ['domain' => '<!channel>']);
        $this->log($game, 'request_live', 'ok', ['domain' => ['x.se']]);

        $this->migrate();

        $instruction = ReleaseSetupStep::where('game_id', $game->id)->value('instruction');
        $this->assertStringNotContainsString('Önskad adress', $instruction);
        $this->assertSame(['web'], $game->fresh()->releaseTargets());
    }

    public function test_it_merges_with_existing_targets_and_can_run_twice(): void
    {
        $game = $this->newGame('asked');
        $game->forceFill(['release_targets' => ['app_store']])->save();
        $this->log($game, 'request_live', 'ok');
        $this->log($game, 'request_live', 'ok');

        $this->migrate();
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'done']);
        $this->migrate();

        $this->assertSame(['app_store', 'web'], $game->fresh()->releaseTargets());
        $this->assertSame(1, ReleaseSetupStep::where('game_id', $game->id)->count());
        // Ett redan klart steg öppnas inte igen.
        $this->assertSame('done', ReleaseSetupStep::where('game_id', $game->id)->value('status'));
    }

    public function test_no_log_rows_means_no_change(): void
    {
        $game = $this->newGame('quiet');

        $this->migrate();

        $this->assertSame([], $game->fresh()->releaseTargets());
    }
}
