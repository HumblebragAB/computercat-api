<?php

namespace Tests\Feature\Releases;

use App\Models\Release;
use App\Releases\ReleaseNotifier;
use App\Releases\ReleaseService;
use App\Support\SlackAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class ReleaseNotifierTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    // Ett enda stub-anrop: ett andra Http::fake efter setUp skulle ignoreras (första träffen vinner),
    // och Http::swap skulle ta bort transaktionsvaktens middleware.
    private int $slackStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
        Http::fake([ProvisioningFakes::SLACK => fn () => Http::response('ok', $this->slackStatus)]);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    public function test_first_production_release_asks_erik_to_approve_with_a_filament_link(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'web', 'web_production', $this->sha());

        $result = app(ReleaseNotifier::class)->afterPromote($release);

        $this->assertSame(SlackAlert::SENT, $result);
        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK
            && str_contains($r['text'], 'godkännande')
            && str_contains($r['text'], 'kattsvep')
            && str_contains($r['text'], $this->sha())
            && str_contains($r['text'], '/admin/games/'.$game->id.'/releases'));
    }

    public function test_later_production_release_informs_erik_without_asking_for_approval(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $this->release($game, ['status' => Release::SUCCEEDED]);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'web', 'web_production', $this->sha());

        app(ReleaseNotifier::class)->afterPromote($release);

        Http::assertSent(fn ($r) => str_contains($r['text'], 'Produktionssläpp begärt') && ! str_contains($r['text'], 'väntar på godkännande'));
    }

    public function test_test_stage_sends_nothing(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $game = $this->releasableGame('kattsvep', ['play_store']);
        $this->demoBuild($game);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'play_store', 'play_internal', null);

        $this->assertNull(app(ReleaseNotifier::class)->afterPromote($release));
        Http::assertNothingSent();
    }

    public function test_slack_failure_is_reported_not_thrown(): void
    {
        $this->slackStatus = 500;
        $game = $this->releasableGame('kattsvep');
        $this->demoBuild($game);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'web', 'web_production', $this->sha());

        $this->assertSame(SlackAlert::FAILED, app(ReleaseNotifier::class)->afterPromote($release));
    }
}
