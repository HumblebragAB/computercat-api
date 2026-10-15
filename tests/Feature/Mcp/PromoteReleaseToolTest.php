<?php

namespace Tests\Feature\Mcp;

use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\Release;
use App\Models\User;
use App\Support\SlackAlert;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;

class PromoteReleaseToolTest extends McpTestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases;

    // Ett enda stub-anrop (ett andra Http::fake efter setUp ignoreras; Http::swap tar bort transaktionsvakten).
    private int $slackStatus = 200;

    private Game $game;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
        Http::fake([ProvisioningFakes::SLACK => fn () => Http::response('ok', $this->slackStatus)]);
        $this->game = $this->releasableGame('kattsvep', ['web', 'app_store', 'play_store']);
        $this->owner = $this->ownerOf($this->game);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    private function promote(array $args, ?User $user = null): array
    {
        return $this->callTool($user ?? $this->owner, 'promote_release', ['game' => 'kattsvep'] + $args);
    }

    public function test_test_stage_defaults_to_the_latest_demo_commit_and_stops_at_requested(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'play_store', 'stage' => 'play_internal']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame('requested', $json['status']);
        $this->assertSame($this->sha('b'), $json['commit_sha']);
        $this->assertSame('queued_for_build', $json['state']);
        // R1: inget byggs i 1a, och svaret säger det.
        $this->assertStringContainsString('etapp 3', $json['next']);
        $this->assertNull($json['notification']);
        Http::assertNothingSent();
    }

    public function test_first_production_release_awaits_approval_and_notifies_erik(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')]);

        $json = json_decode($result['text'], true);
        $this->assertSame('awaiting_approval', $json['status']);
        $this->assertSame('sent', $json['notification']);
        $this->assertStringContainsString('godkännas i Filament', $json['next']);
        Http::assertSentCount(1);
        $log = McpAuditLog::where('tool', 'promote_release')->latest('id')->first();
        $this->assertSame('ok', $log->outcome);
        $this->assertSame($this->sha('b'), $log->arguments['commit_sha']);
        $this->assertSame('web_production', $log->arguments['stage']);
    }

    public function test_later_production_release_is_requested_and_still_tells_erik(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));
        $this->release($this->game, ['status' => Release::SUCCEEDED]);

        $json = json_decode($this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')])['text'], true);

        $this->assertSame('requested', $json['status']);
        $this->assertStringContainsString('etapp 1b', $json['next']);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Produktionssläpp begärt'));
    }

    public function test_production_is_closed_in_this_version(): void // R1
    {
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]);
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Produktion byggs i etapp 1b', $result['text']);
        $this->assertSame(0, Release::count());
        Http::assertNothingSent();
    }

    public function test_slack_failure_does_not_fail_the_promotion_but_is_visible(): void
    {
        $this->slackStatus = 500;
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')]);

        $this->assertTrue($result['ok']);
        $this->assertSame('failed', json_decode($result['text'], true)['notification']);
        $this->assertSame(1, Release::count());
    }

    public function test_only_the_owner_may_promote(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game);
        $member = $this->staffMember($this->game);

        $result = $this->promote(['target' => 'play_store', 'stage' => 'play_internal'], $member);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Only the game owner', $result['text']);
        $this->assertSame(0, Release::count());
    }

    public function test_validation_errors(): void
    {
        $this->demoBuild($this->game);

        foreach ([
            ['target' => 'nonsense', 'stage' => 'testflight'],
            ['target' => 'web', 'stage' => 'demo'],
            ['target' => ['web'], 'stage' => 'web_production'],
            ['stage' => 'web_production'],
            ['target' => 'web'],
            ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => ['x']],
        ] as $args) {
            $result = $this->promote($args);
            $this->assertFalse($result['ok'], json_encode($args));
            $this->assertStringContainsString('Validation failed', $result['text'], json_encode($args));
        }
        $this->assertSame(0, Release::count());
    }

    public function test_gate_refusals_are_errors_without_side_effects(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));
        // play_store är på i setUp (play_internal ersätter testflight som allmänt teststeg); stängs här för fallet "not enabled".
        $this->game->forceFill(['release_targets' => ['web', 'app_store']])->save();

        $cases = [
            [['target' => 'web', 'stage' => 'web_production'], 'commit_sha is required for production stages'],
            [['target' => 'web', 'stage' => 'web_production', 'commit_sha' => 'HEAD'], '40-character'],
            [['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('c')], 'no successful Demo build'],
            [['target' => 'web', 'stage' => 'testflight'], 'cannot be promoted'],
            [['target' => 'play_store', 'stage' => 'play_internal'], 'not enabled'],
            [['target' => 'app_store', 'stage' => 'app_store', 'commit_sha' => $this->sha('b')], 'has not succeeded in testflight'],
        ];
        foreach ($cases as [$args, $needle]) {
            $result = $this->promote($args);
            $this->assertFalse($result['ok'], json_encode($args));
            $this->assertStringContainsString($needle, $result['text'], json_encode($args));
        }
        $this->assertSame(0, Release::count());
        Http::assertNothingSent();
    }

    public function test_empty_commit_sha_counts_as_omitted(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'play_store', 'stage' => 'play_internal', 'commit_sha' => '']);

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame($this->sha('b'), json_decode($result['text'], true)['commit_sha']);
    }

    public function test_switch_off_stops_new_promotions(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game);
        $this->game->forceFill(['releases_enabled' => false])->save();

        $result = $this->promote(['target' => 'play_store', 'stage' => 'play_internal']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('switched off', $result['text']);
        $this->assertSame(0, Release::count());
    }

    public function test_second_call_while_pending_is_refused(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game);
        $this->promote(['target' => 'play_store', 'stage' => 'play_internal']);

        $again = $this->promote(['target' => 'play_store', 'stage' => 'play_internal']);

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already pending', $again['text']);
        $this->assertSame(1, Release::count());
    }

    public function test_notifier_exception_keeps_the_release_and_warns(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));
        $this->mock(SlackAlert::class, fn ($m) => $m->shouldReceive('send')->andThrow(new \RuntimeException('cache down')));

        $result = $this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')]);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame('failed', $json['notification']);
        $this->assertStringContainsString('NOT notified', $json['warning']);
        $this->assertSame(1, Release::count());
    }

    public function test_slack_failure_carries_a_warning_but_test_stage_does_not(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->slackStatus = 500;
        $this->demoBuild($this->game, $this->sha('b'));
        $json = json_decode($this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')])['text'], true);
        $this->assertArrayHasKey('warning', $json);

        $json = json_decode($this->promote(['target' => 'play_store', 'stage' => 'play_internal'])['text'], true);
        $this->assertArrayNotHasKey('warning', $json);
    }
}
