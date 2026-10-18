<?php

namespace Tests\Feature\Releases;

use App\Jobs\RunAppStoreSetup;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\AppStore\AppStoreSetup;
use App\Releases\AppStore\AppStoreSetupFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\AppStoreFakes as A;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class AppStoreSetupTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        A::configure();
        $this->startHttpTransactionGuard();
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    private function freshAppStoreGame()
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $game->forceFill(['bundle_id' => 'se.computercat.kattsvep', 'app_store_ids' => null])->save();
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => ReleaseSetupStep::OPEN, 'done_at' => null]);

        return $game->fresh();
    }

    private function stepStatus($game): array
    {
        return ReleaseSetupStep::where('game_id', $game->id)->pluck('status', 'key')->all();
    }

    /** GET /v1/users/{id}: de två testarnas e-post. */
    private function users(): array
    {
        return [A::API.'/v1/users/*' => function (Request $r) {
            $id = (string) preg_replace('#^.*/v1/users/([^?]+).*$#', '$1', $r->url());

            return Http::response(A::one(A::user($id, A::TESTER_EMAILS[$id])));
        }];
    }

    public function test_all_automatic_steps_run_in_order_and_the_group_alone_is_enough_before_a_build(): void
    {
        Log::spy();
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many()),
            A::API.'/v1/bundleIds' => Http::response(A::one(A::bundleId('se.computercat.kattsvep')), 201),
            A::API.'/v1/profiles?*' => Http::response(A::many()),
            A::API.'/v1/profiles' => Http::response(A::one(A::profile()), 201),
            A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/betaGroups?*' => Http::response(A::many()),
            A::API.'/v1/betaGroups' => Http::response(A::one(A::betaGroup()), 201),
        ]);

        $done = app(AppStoreSetup::class)->run($game);

        // Eriks beslut 2026-10-18: gruppen räcker för första bygget; testarna läggs till efter ett giltigt bygge.
        $this->assertSame(['appstore_bundle_id', 'appstore_profile', 'appstore_create_app', 'appstore_testflight_group'], $done);
        $this->assertSame('open', $this->stepStatus($game)['appstore_testflight_testers']);
        $this->assertSame('open', $this->stepStatus($game)['appstore_privacy_and_age']);
        $ids = $game->fresh()->app_store_ids;
        $this->assertSame([A::BUNDLE_RESOURCE, A::PROFILE, A::APP, A::GROUP], [$ids['bundle_resource_id'], $ids['profile_id'], $ids['app_id'], $ids['beta_group_id']]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/users/') || str_contains($r->url(), 'betaTesters'));
        Http::assertSent(fn (Request $r) => $r->url() === A::API.'/v1/profiles' && $r->data()['data']['relationships']['certificates']['data'][0]['id'] === A::CERT);
        $this->assertNull(ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('done_by'));
        $this->assertNull(ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->value('last_error'));
    }

    /** Spel med alla steg klara utom testarna, och ett lyckat TestFlight-släpp (bygget är VALID och i gruppen). */
    private function gameWithValidBuild()
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->update(['status' => ReleaseSetupStep::OPEN, 'done_at' => null]);
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED, 'marketing_version' => '1.0.0', 'build_number' => 1]);

        return $game->fresh();
    }

    /** GET /v1/betaGroups/{id}/betaTesters: gruppens medlemmar (e-post). */
    private function members(string ...$emails): array
    {
        return [A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?*' => Http::response(A::many(...array_map(fn ($e) => A::betaTester($e, 'm-'.md5($e)), $emails)))];
    }

    private function testers_step($game): ReleaseSetupStep
    {
        return ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->firstOrFail();
    }

    public function test_testers_wait_for_a_valid_build(): void
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->update(['status' => ReleaseSetupStep::OPEN]);
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::PROCESSING, 'marketing_version' => '1.0.0', 'build_number' => 1]);
        Http::fake();

        $this->assertSame([], app(AppStoreSetup::class)->run($game->fresh()));
        Http::assertNothingSent();
        $this->assertSame('open', $this->testersStep($game)->status);
    }

    public function test_testers_are_created_in_the_group_after_a_valid_build(): void
    {
        Log::spy();
        $game = $this->gameWithValidBuild();
        Http::fake($this->users() + $this->members() + [
            A::API.'/v1/betaTesters' => Http::sequence()->push(A::one(A::betaTester('erik@example.test', 't-1')), 201)->push(A::one(A::betaTester('mattias@example.test', 't-2')), 201),
        ]);

        $this->assertSame(['appstore_testflight_testers'], app(AppStoreSetup::class)->run($game));

        $this->assertSame(A::TESTER_IDS, $game->fresh()->app_store_ids['testers_added']);
        Http::assertSent(fn (Request $r) => $r->url() === A::API.'/v1/betaTesters' && $r->method() === 'POST'
            && $r->data()['data']['relationships']['betaGroups']['data'] === [['type' => 'betaGroups', 'id' => A::GROUP]]);
        // Den länkade vägen (POST relationships/betaTesters med en teamövergripande testare) används aldrig.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/relationships/betaTesters'));
        $this->assertSame('done', $this->testersStep($game)->status);
        // R18: inga e-postadresser i loggen, varken råa eller URL-kodade.
        foreach (['notice', 'info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$args) => preg_match('/example\.test|%40example/', json_encode($args)) === 1);
        }
    }

    public function test_tester_already_in_the_group_is_skipped(): void
    {
        $game = $this->gameWithValidBuild();
        // Gruppens lista är sanningen (också när Erik lagt till någon för hand); jämförelsen är skiftlägesokänslig.
        Http::fake($this->users() + $this->members('Erik@Example.test') + [
            A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('mattias@example.test', 't-2')), 201),
        ]);

        app(AppStoreSetup::class)->run($game);

        Http::assertSentCount(5); // erik: user + medlemmar; mattias: user + medlemmar + create
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['data']['attributes']['email'] ?? null) === 'mattias@example.test');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['data']['attributes']['email'] ?? null) === 'erik@example.test');
        $this->assertSame('done', $this->testersStep($game)->status);
    }

    public function test_tester_without_access_to_the_app_gets_an_actionable_error_and_the_others_are_added(): void
    {
        $game = $this->gameWithValidBuild();
        [$erik, $mattias] = A::TESTER_IDS;
        Http::fake($this->members() + [
            A::API.'/v1/users/'.$erik.'/visibleApps?*' => Http::response(A::many(A::app('se.computercat.annat', '999'))),
            A::API.'/v1/users/'.$erik.'?*' => Http::response(A::one(A::user($erik, 'erik@example.test', allAppsVisible: false))),
            A::API.'/v1/users/'.$mattias.'?*' => Http::response(A::one(A::user($mattias, 'mattias@example.test'))),
            A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('mattias@example.test', 't-2')), 201),
        ]);

        try {
            app(AppStoreSetup::class)->run($game);
            $this->fail('Felet kastades inte.');
        } catch (AppStoreSetupFailed $e) {
            $this->assertStringContainsString("Användaren {$erik} har inte tillgång till appen i App Store Connect; ge åtkomst eller ta bort hen ur releases.app_store.tester_user_ids", $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['data']['attributes']['email'] ?? null) === 'erik@example.test');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['data']['attributes']['email'] ?? null) === 'mattias@example.test');
        $step = $this->testersStep($game);
        $this->assertSame('open', $step->status);
        $this->assertStringContainsString($erik, $step->last_error);
        $this->assertStringNotContainsString('example.test', $step->last_error);
        $this->assertSame([$mattias], $game->fresh()->app_store_ids['testers_added']);
    }

    public function test_tester_with_the_app_among_visible_apps_is_created(): void
    {
        $game = $this->gameWithValidBuild();
        config(['releases.app_store.tester_user_ids' => [A::TESTER_IDS[0]]]);
        $erik = A::TESTER_IDS[0];
        Http::fake($this->members() + [
            A::API.'/v1/users/'.$erik.'/visibleApps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/users/'.$erik.'?*' => Http::response(A::one(A::user($erik, 'erik@example.test', allAppsVisible: false))),
            A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('erik@example.test', 't-1')), 201),
        ]);

        $this->assertSame(['appstore_testflight_testers'], app(AppStoreSetup::class)->run($game));
    }

    public function test_409_with_the_tester_present_on_read_back_counts_as_done(): void
    {
        $game = $this->gameWithValidBuild();
        config(['releases.app_store.tester_user_ids' => [A::TESTER_IDS[0]]]);
        Http::fake($this->users() + [
            A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?*' => Http::sequence()->push(A::many())->push(A::many(A::betaTester('erik@example.test', 't-1'))),
            A::API.'/v1/betaTesters' => Http::response(A::error(409, 'STATE_ERROR', 'Tester(s) cannot be assigned'), 409),
        ]);

        $this->assertSame(['appstore_testflight_testers'], app(AppStoreSetup::class)->run($game));
        Http::assertSentCount(5); // user, medlemmar, create (409) | user, medlemmar (läst tillbaka)
        $this->assertSame([A::TESTER_IDS[0]], $game->fresh()->app_store_ids['testers_added']);
    }

    public function test_409_with_the_tester_missing_on_read_back_fails_loud_without_creating_again(): void
    {
        $game = $this->gameWithValidBuild();
        $erik = A::TESTER_IDS[0];
        config(['releases.app_store.tester_user_ids' => [$erik]]);
        Http::fake($this->users() + $this->members() + [
            A::API.'/v1/betaTesters' => Http::response(A::error(409, 'STATE_ERROR', 'Tester(s) cannot be assigned'), 409),
        ]);

        try {
            app(AppStoreSetup::class)->run($game);
            $this->fail('Felet kastades inte.');
        } catch (AppStoreSetupFailed $e) {
            $this->assertStringContainsString("användaren {$erik}", $e->getMessage());
            $this->assertStringContainsString('409', $e->getMessage());
            $this->assertStringContainsString('releases.app_store.tester_user_ids', $e->getMessage());
            $this->assertStringNotContainsString('example.test', $e->getMessage());
        }
        Http::assertSentCount(5);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'));
        $this->assertSame('open', $this->testersStep($game)->status);
    }

    public function test_a_failed_tester_is_tried_again_on_the_next_run(): void
    {
        $game = $this->gameWithValidBuild();
        $erik = A::TESTER_IDS[0];
        config(['releases.app_store.tester_user_ids' => [$erik]]);
        Http::fake($this->members() + [
            A::API.'/v1/users/'.$erik.'/visibleApps?*' => Http::sequence()->push(A::many())->push(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/users/'.$erik.'?*' => Http::response(A::one(A::user($erik, 'erik@example.test', allAppsVisible: false))),
            A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('erik@example.test', 't-1')), 201),
        ]);

        try {
            app(AppStoreSetup::class)->run($game);
            $this->fail('Felet kastades inte.');
        } catch (AppStoreSetupFailed) {
        }
        // Erik ger åtkomst; nästa körning försöker igen.
        $this->assertSame(['appstore_testflight_testers'], app(AppStoreSetup::class)->run($game->fresh()));
        $step = $this->testersStep($game);
        $this->assertNull($step->last_error);
        $this->assertSame(0, $step->failed_attempts);
        $this->assertNull($step->retry_at);
    }

    public function test_a_failing_step_backs_off_exponentially(): void
    {
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many()),
            A::API.'/v1/bundleIds' => Http::response(A::error(409, 'ENTITY_ERROR', 'An attribute value is not acceptable'), 409),
        ]);
        $this->freezeTime();

        foreach ([10, 20, 40] as $minutes) {
            try {
                app(AppStoreSetup::class)->runOne($game->fresh());
                $this->fail('Felet kastades inte.');
            } catch (ExternalApiException) {
            }
            $step = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->first();
            $this->assertSame(now()->addMinutes($minutes)->toIso8601String(), $step->retry_at->toIso8601String());
        }
        $this->assertSame(3, $step->failed_attempts);
        $this->assertSame(AppStoreSetup::BACKOFF_MAX_MINUTES, AppStoreSetup::backoffMinutes(50));
    }

    public function test_legacy_open_group_step_with_a_stored_group_becomes_done_without_calls(): void
    {
        // Reveal före rättningen: gruppen skapad (beta_group_id sparat), steget öppet och fast på testarna.
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_group')
            ->update(['status' => ReleaseSetupStep::OPEN, 'done_at' => null, 'last_error' => 'App Store Connect returned 409', 'failed_attempts' => 0]);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->delete(); // fanns inte före rättningen
        Http::fake();

        (require database_path('migrations/2026_10_18_100001_add_backoff_and_testers_step_to_release_setup_steps.php'))->syncAppStoreSteps();
        $this->assertSame(['appstore_testflight_group'], app(AppStoreSetup::class)->run($game->fresh()));

        Http::assertNothingSent();
        $status = $this->stepStatus($game);
        $this->assertSame('done', $status['appstore_testflight_group']);
        $this->assertSame('open', $status['appstore_testflight_testers']);
        $this->assertNull(ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_group')->value('last_error'));
    }

    public function test_one_unit_makes_at_most_four_calls(): void // timeout-budgeten (R11, Task 5)
    {
        $game = $this->freshAppStoreGame();
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED, 'marketing_version' => '1.0.0', 'build_number' => 1]);
        [$erik, $mattias] = A::TESTER_IDS;
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/profiles?*' => Http::response(A::many(A::profile())),
            A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/betaGroups?*' => Http::response(A::many(A::betaGroup())),
            A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?*' => Http::response(A::many()),
            A::API.'/v1/users/*/visibleApps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/users/'.$erik.'?*' => Http::response(A::one(A::user($erik, 'erik@example.test', allAppsVisible: false))),
            A::API.'/v1/users/'.$mattias.'?*' => Http::response(A::one(A::user($mattias, 'mattias@example.test', allAppsVisible: false))),
            A::API.'/v1/betaTesters' => Http::sequence()->push(A::error(409, 'STATE_ERROR', 'Tester(s) cannot be assigned'), 409)->push(A::one(A::betaTester('x@example.test', 't-9')), 201),
        ]);

        $setup = app(AppStoreSetup::class);
        $before = 0;
        do {
            try {
                [$outcome] = $setup->runOne($game->fresh());
            } catch (AppStoreSetupFailed) {
                $outcome = AppStoreSetup::IDLE;
            }
            $calls = count(Http::recorded());
            $this->assertLessThanOrEqual(4, $calls - $before);
            $before = $calls;
        } while ($outcome === AppStoreSetup::PROGRESS);
        $this->assertGreaterThan(8, $before); // testarenheterna kördes
    }

    public function test_create_app_step_is_done_only_when_apple_has_the_app(): void // R8
    {
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/profiles?*' => Http::response(A::many(A::profile())),
            A::API.'/v1/apps?*' => Http::response(A::many()),
        ]);

        $done = app(AppStoreSetup::class)->run($game);

        $this->assertSame(['appstore_bundle_id', 'appstore_profile'], $done);
        $this->assertSame('open', $this->stepStatus($game)['appstore_create_app']);
        $this->assertSame('open', $this->stepStatus($game)['appstore_testflight_group']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/betaGroups'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_done_steps_without_ids_are_reopened(): void // R17: 1a-data
    {
        $game = $this->releasableGame('kattsvep', ['app_store']); // alla steg klara, inga id:n
        $game->forceFill(['bundle_id' => 'se.computercat.kattsvep'])->save();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/profiles?*' => Http::response(A::many(A::profile())),
            A::API.'/v1/apps?*' => Http::response(A::many()),
        ]);

        app(AppStoreSetup::class)->run($game->fresh());

        $status = $this->stepStatus($game);
        $this->assertSame(['done', 'done', 'open', 'open'], [$status['appstore_bundle_id'], $status['appstore_profile'], $status['appstore_create_app'], $status['appstore_testflight_group']]);
        $this->assertStringContainsString('app_id saknas', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('last_error'));
        $this->assertSame('done', $status['appstore_privacy_and_age']); // Eriks steg utan id rörs inte
    }

    public function test_a_second_run_creates_nothing_new(): void
    {
        $game = $this->testFlightGame();
        $game->forceFill(['app_store_ids' => $game->app_store_ids + ['testers_added' => A::TESTER_IDS]])->save();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->update(['status' => ReleaseSetupStep::OPEN]);
        Http::fake();

        // Alla testare redan tillagda: steget blir klart utan anrop, också innan något bygge finns.
        $this->assertSame(['appstore_testflight_testers'], app(AppStoreSetup::class)->run($game->fresh()));
        Http::assertNothingSent();
    }

    public function test_an_error_is_saved_on_the_step_redacted_and_rethrown(): void
    {
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many()),
            A::API.'/v1/bundleIds' => Http::response(A::error(403, 'FORBIDDEN_ERROR', 'The API key in use does not allow this request'), 403),
        ]);

        try {
            app(AppStoreSetup::class)->run($game);
            $this->fail('Felet kastades inte.');
        } catch (ExternalApiException) {
            $error = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->value('last_error');
            $this->assertStringContainsString('returned 403', $error);
            $this->assertStringNotContainsString('eyJ', $error);
            $this->assertSame('open', $this->stepStatus($game)['appstore_bundle_id']);
        }
    }

    public function test_game_without_app_store_target_is_left_alone(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        Http::fake();

        $this->assertSame([], app(AppStoreSetup::class)->run($game));
        Http::assertNothingSent();
    }

    public function test_job_does_one_unit_per_run(): void
    {
        $game = $this->freshAppStoreGame();
        Http::fake([A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep')))]);

        app()->call([new RunAppStoreSetup($game->id), 'handle']);

        $this->assertSame('done', $this->stepStatus($game)['appstore_bundle_id']);
        $this->assertSame('open', $this->stepStatus($game)['appstore_profile']);
        Http::assertSentCount(1);
    }
}
