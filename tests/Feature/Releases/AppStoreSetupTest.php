<?php

namespace Tests\Feature\Releases;

use App\Jobs\RunAppStoreSetup;
use App\Models\ReleaseSetupStep;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\AppStore\AppStoreSetup;
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

    public function test_all_automatic_steps_run_in_order_when_the_app_exists(): void
    {
        Log::spy();
        $game = $this->freshAppStoreGame();
        Http::fake($this->users() + [
            A::API.'/v1/bundleIds?*' => Http::response(A::many()),
            A::API.'/v1/bundleIds' => Http::response(A::one(A::bundleId('se.computercat.kattsvep')), 201),
            A::API.'/v1/profiles?*' => Http::response(A::many()),
            A::API.'/v1/profiles' => Http::response(A::one(A::profile()), 201),
            A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/betaGroups?*' => Http::response(A::many()),
            A::API.'/v1/betaGroups' => Http::response(A::one(A::betaGroup()), 201),
            A::API.'/v1/betaTesters?*' => Http::response(A::many()),
            A::API.'/v1/betaTesters' => Http::sequence()->push(A::one(A::betaTester('erik@example.test', 't-1')), 201)->push(A::one(A::betaTester('mattias@example.test', 't-2')), 201),
        ]);

        $done = app(AppStoreSetup::class)->run($game);

        $this->assertSame(['appstore_bundle_id', 'appstore_profile', 'appstore_create_app', 'appstore_testflight_group'], $done);
        $this->assertSame('open', $this->stepStatus($game)['appstore_privacy_and_age']);
        $ids = $game->fresh()->app_store_ids;
        $this->assertSame([A::BUNDLE_RESOURCE, A::PROFILE, A::APP, A::GROUP], [$ids['bundle_resource_id'], $ids['profile_id'], $ids['app_id'], $ids['beta_group_id']]);
        $this->assertSame(A::TESTER_IDS, $ids['testers_added']);
        Http::assertSent(fn (Request $r) => $r->url() === A::API.'/v1/profiles' && $r->data()['data']['relationships']['certificates']['data'][0]['id'] === A::CERT);
        $this->assertNull(ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('done_by'));
        // R18: inga e-postadresser i loggen, varken råa eller URL-kodade.
        foreach (['notice', 'info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$args) => preg_match('/example\.test|%40example/', json_encode($args)) === 1);
        }
    }

    public function test_one_unit_makes_at_most_four_calls(): void // timeout-budgeten (R11, Task 5)
    {
        $game = $this->freshAppStoreGame();
        Http::fake($this->users() + [
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/profiles?*' => Http::response(A::many(A::profile())),
            A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/betaGroups?*' => Http::response(A::many(A::betaGroup())),
            A::API.'/v1/betaTesters?*' => Http::response(A::many()),
            A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('x@example.test', 't-9')), 201),
        ]);

        $setup = app(AppStoreSetup::class);
        $before = 0;
        do {
            [$outcome] = $setup->runOne($game->fresh());
            $calls = count(Http::recorded());
            $this->assertLessThanOrEqual(4, $calls - $before);
            $before = $calls;
        } while ($outcome === AppStoreSetup::PROGRESS);
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
        Http::fake();

        $this->assertSame([], app(AppStoreSetup::class)->run($game->fresh()));
        Http::assertNothingSent();
    }

    public function test_existing_tester_is_linked_and_member_is_left_alone(): void
    {
        $game = $this->freshAppStoreGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', '!=', 'appstore_testflight_group')->update(['status' => ReleaseSetupStep::DONE]);
        $game->forceFill(['app_store_ids' => ['bundle_resource_id' => A::BUNDLE_RESOURCE, 'profile_id' => A::PROFILE, 'app_id' => A::APP]])->save();
        Http::fake($this->users() + [
            A::API.'/v1/betaGroups?*' => Http::response(A::many(A::betaGroup())),
            // erik är redan med i gruppen; mattias finns som testare men inte i gruppen.
            A::API.'/v1/betaTesters?*' => function (Request $r) {
                $url = urldecode($r->url());
                if (str_contains($url, 'erik@example.test')) {
                    return Http::response(A::many(A::betaTester('erik@example.test', 't-1')));
                }

                return Http::response(str_contains($url, 'filter[betaGroups]') ? A::many() : A::many(A::betaTester('mattias@example.test', 't-2')));
            },
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/betaTesters' => Http::response('', 204),
        ]);

        app(AppStoreSetup::class)->run($game->fresh());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/betaTesters') && $r->data() === ['data' => [['type' => 'betaTesters', 'id' => 't-2']]]);
        Http::assertNotSent(fn (Request $r) => $r->url() === A::API.'/v1/betaTesters' && $r->method() === 'POST');
        $this->assertSame('done', $this->stepStatus($game)['appstore_testflight_group']);
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
