<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Releases\TestFlight\ReleaseFailed;
use App\Releases\TestFlight\TestFlightBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AppStoreFakes as A;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class TestFlightBuilderTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    private const RELEASE_REPO = F::GITHUB.'/repos/HumblebragAB/computercat-release';

    private const GAME_REPO = F::GITHUB.'/repos/HumblebragAB/kattsvep';

    private Game $game;

    private Release $release;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        A::configure();
        Http::preventStrayRequests(); // ett ofejkat anrop är ett testfel, aldrig ett riktigt anrop
        $this->startHttpTransactionGuard();
        $this->game = $this->testFlightGame();
        $this->demoBuild($this->game, $this->sha('a'));
        $this->game->forceFill(['ios_build_number' => 5])->save();
        $this->release = $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::REQUESTED,
            'commit_sha' => $this->sha('a'), 'marketing_version' => '1.2.0', 'build_number' => 5, 'build_guide' => 'v3']);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    /** Standardsvar; $routes går före (första matchande mönster vinner). */
    private function fake(array $routes = []): void
    {
        Http::fake($routes + [
            F::SLACK => Http::response('ok'),
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            A::API.'/v1/profiles/'.A::PROFILE.'?*' => Http::response(A::one(A::profile())),
            self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(['message' => 'Not Found'], 404),
            self::GAME_REPO.'/git/refs' => Http::response(F::githubTagRef('v1.2.0', $this->sha('a')), 201),
            self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => Http::response(F::githubDispatch(4711)),
            self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 0, 'workflow_runs' => []]),
            self::RELEASE_REPO.'/actions/runs/*/cancel' => Http::response('', 202),
        ]);
    }

    private function advance(): ?int
    {
        $release = Release::with('game')->find($this->release->id);

        return app(TestFlightBuilder::class)->advance($release);
    }

    private function fresh(): Release
    {
        return $this->release->fresh();
    }

    private function building(array $external = []): void
    {
        $this->release->update(['status' => Release::BUILDING, 'external' => $external + ['claimed_at' => now()->toIso8601String(), 'tag' => 'v1.2.0', 'github_run_id' => 4711, 'dispatched_at' => now()->toIso8601String(), 'dispatch_requested_at' => now()->toIso8601String()]]);
    }

    /** I besittning och dispatch begärd, men inget körnings-id (R15). */
    private function dispatchPending(int $minutesAgo = 0): void
    {
        $at = now()->subMinutes($minutesAgo)->toIso8601String();
        $this->release->update(['status' => Release::BUILDING, 'external' => ['claimed_at' => $at, 'tag' => 'v1.2.0', 'dispatch_requested_at' => $at]]);
    }

    private function processing(array $external = []): void
    {
        $this->release->update(['status' => Release::PROCESSING, 'external' => $external + ['github_run_id' => 4711, 'run_completed_at' => now()->toIso8601String()]]);
    }

    public function test_start_tags_dispatches_with_computercats_values_and_moves_to_building(): void
    {
        $this->fake();

        $this->assertSame(30, $this->advance());

        $release = $this->fresh();
        $this->assertSame(Release::BUILDING, $release->status);
        $this->assertSame([4711, 'v1.2.0'], [$release->external['github_run_id'], $release->external['tag']]);
        Http::assertSent(fn (Request $r) => $r->url() === self::GAME_REPO.'/git/refs' && $r->data() === ['ref' => 'refs/tags/v1.2.0', 'sha' => $this->sha('a')]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches') && $r->data() === ['ref' => 'main', 'inputs' => [
            'game' => 'kattsvep', 'commit_sha' => $this->sha('a'), 'release_uuid' => $this->release->uuid, 'build_number' => '5',
            'marketing_version' => '1.2.0', 'bundle_id' => 'se.computercat.kattsvep', 'profile_id' => A::PROFILE, 'build_guide' => 'v3',
        ], 'return_run_details' => true]);
        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->values()->all();
        $this->assertLessThan(array_search(self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches', $urls, true), array_search(self::GAME_REPO.'/git/refs', $urls, true), 'Taggen sätts före dispatch.');
    }

    public function test_existing_tag_on_the_same_commit_is_reused(): void
    {
        $this->fake([self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', $this->sha('a')))]);

        $this->advance();

        $this->assertSame(Release::BUILDING, $this->fresh()->status);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::GAME_REPO.'/git/refs');
    }

    public function test_tag_on_another_commit_fails_without_dispatch(): void
    {
        $this->fake([self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', $this->sha('f')))]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('Tag v1.2.0 now points at another commit', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK);
    }

    public function test_inactive_or_expiring_profile_fails_before_tag_and_dispatch(): void // R13
    {
        $this->fake([A::API.'/v1/profiles/'.A::PROFILE.'?*' => Http::response(A::one(A::profile(expires: now()->addHours(3)->toIso8601String())))]);

        $this->advance();

        $this->assertStringContainsString('profile is not active or expires within a day', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/git/'));
    }

    public function test_switch_off_before_start_fails_with_the_reason(): void // spärr 9 vid byggstart
    {
        $this->game->forceFill(['releases_enabled' => false])->save();
        $this->fake();

        $this->advance();

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('switched off', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'github.com/repos'));
    }

    public function test_release_from_1a_without_version_fails_clearly(): void // R14
    {
        $this->release->update(['marketing_version' => null, 'build_number' => null, 'build_guide' => null]);
        $this->fake();

        $this->advance();

        $this->assertStringContainsString('registered before TestFlight builds existed', $this->fresh()->last_error);
    }

    public function test_lost_dispatch_response_is_recovered_by_run_name(): void // Review Focus 2
    {
        $this->dispatchPending(1);
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 1, 'workflow_runs' => [F::githubRun(4712, 'in_progress', null, $this->release->uuid)]])]);

        $this->advance();

        $this->assertSame([Release::BUILDING, 4712], [$this->fresh()->status, $this->fresh()->external['github_run_id']]);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
    }

    public function test_start_never_dispatches_twice(): void
    {
        $this->dispatchPending();
        $this->fake();

        $this->assertSame(15, $this->advance());
        $this->travel(4)->minutes();
        $this->assertNull($this->advance());

        $this->assertStringContainsString('no run was found', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
    }

    public function test_lost_dispatch_found_after_the_window_is_adopted(): void // R15: uppslaget går före tidsgränsen
    {
        $this->dispatchPending(5);
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 1, 'workflow_runs' => [F::githubRun(4713, 'completed', 'success', $this->release->uuid)]])]);

        $this->advance();

        $this->assertSame([Release::BUILDING, 4713], [$this->fresh()->status, $this->fresh()->external['github_run_id']]);
    }

    public function test_dispatch_5xx_keeps_the_release_and_the_run_is_found_next_time(): void // R15
    {
        $this->fake([
            self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => Http::response(['message' => 'Server Error'], 502),
            self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::sequence()
                ->push(['total_count' => 1, 'workflow_runs' => [F::githubRun(4714, 'queued', null, $this->release->uuid)]]),
        ]);

        $this->assertSame(15, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
        $this->assertArrayHasKey('dispatch_requested_at', $this->fresh()->external);
        $this->assertNull($this->fresh()->last_error);

        $this->advance();

        $this->assertSame(4714, $this->fresh()->external['github_run_id']);
        Http::assertSentCount(1 + 1 + 1 + 1 + 1 + 1); // token, profil, tagg (GET), tagg (POST), dispatch, uppslag
    }

    public function test_dispatch_with_broken_connection_keeps_the_release(): void // R15
    {
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => fn () => throw new ConnectionException('reset')]);

        $this->assertSame(15, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
        $this->assertArrayNotHasKey('github_run_id', $this->fresh()->external);
    }

    public function test_dispatch_204_without_a_run_id_keeps_the_release(): void // R15
    {
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => Http::response('', 204)]);

        $this->assertSame(15, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
    }

    public function test_dispatch_4xx_fails_without_a_lookup(): void // R15
    {
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => Http::response(['message' => 'Unexpected inputs provided: ["x"]'], 422)]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('GitHub refused to start the release workflow', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/runs'));
    }

    public function test_release_cancelled_before_it_is_claimed_is_never_built(): void // R16
    {
        $release = $this->release;
        $this->fake([A::API.'/v1/profiles/'.A::PROFILE.'?*' => function () use ($release) {
            // Erik avbryter i Filament medan jobbet läser profilen.
            Release::whereKey($release->id)->update(['status' => Release::CANCELLED]);

            return Http::response(A::one(A::profile()));
        }]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::CANCELLED, $this->fresh()->status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'github.com'));
    }

    public function test_a_claimed_release_cannot_be_cancelled_in_computercat(): void // R16
    {
        $this->dispatchPending();

        $this->expectException(ReleaseRefused::class);
        app(ReleaseService::class)->cancel($this->fresh(), $this->adminUser());
    }

    public function test_successful_run_moves_to_processing_and_records_the_workflow_version(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'success', $this->release->uuid))]);

        $this->assertSame(120, $this->advance());

        $this->assertSame(Release::PROCESSING, $this->fresh()->status);
        $this->assertSame(str_repeat('9', 40), $this->fresh()->external['workflow_sha']);
    }

    public function test_running_run_is_polled_again(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'in_progress', null, $this->release->uuid))]);

        $this->assertSame(30, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
    }

    public function test_failed_run_names_the_job_and_step(): void
    {
        $this->building();
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs(['build', 'Kontrollera version'])),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'failure', $this->release->uuid)),
        ]);

        $this->assertNull($this->advance());

        $this->assertSame('The GitHub run ended with failure in job build, step "Kontrollera version".', $this->fresh()->last_error);
    }

    public function test_run_cancelled_outside_computercat_fails_with_its_conclusion(): void // Review Focus 3
    {
        $this->building();
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs()),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'cancelled', $this->release->uuid)),
        ]);

        $this->advance();

        $this->assertStringContainsString('ended with cancelled', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/cancel')); // redan slut
    }

    public function test_timed_out_conclusion_fails_with_it(): void
    {
        $this->building();
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs(['sign', 'Ladda upp till App Store Connect'])),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'timed_out', $this->release->uuid)),
        ]);

        $this->advance();

        $this->assertStringContainsString('ended with timed_out in job sign', $this->fresh()->last_error);
    }

    public function test_run_timeout_cancels_and_alerts(): void // R11
    {
        $this->building(['dispatched_at' => now()->subMinutes(91)->toIso8601String()]);
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/cancel' => Http::response('', 202),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'queued', null, $this->release->uuid)),
        ]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('did not finish within 90 minutes', $this->fresh()->last_error);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/runs/4711/cancel')));
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK && str_contains($r['text'], 'kattsvep'));
    }

    public function test_valid_build_is_added_to_the_group_and_the_release_succeeds(): void
    {
        $this->processing();
        $this->fake([
            A::API.'/v1/builds?*' => function (Request $r) {
                return str_contains(urldecode($r->url()), 'filter[betaGroups]')
                    ? Http::response(A::many())
                    : Http::response(A::many(A::build('build-uuid-1', 5, 'VALID')));
            },
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/builds' => Http::response('', 204),
        ]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::SUCCEEDED, $this->fresh()->status);
        $this->assertSame('build-uuid-1', $this->fresh()->external['apple_build_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/builds') && $r->data() === ['data' => [['type' => 'builds', 'id' => 'build-uuid-1']]]);
    }

    public function test_build_already_in_the_group_is_not_added_again(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('build-uuid-1', 5, 'VALID')))]);

        $this->advance();

        $this->assertSame(Release::SUCCEEDED, $this->fresh()->status);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/builds'));
    }

    public function test_build_not_yet_visible_or_processing_is_polled_again(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::sequence()->push(A::many())->push(A::many(A::build('b', 5, 'PROCESSING')))]);

        $this->assertSame(120, $this->advance());
        $this->assertSame(120, $this->advance());
        $this->assertSame(Release::PROCESSING, $this->fresh()->status);
    }

    public function test_invalid_build_fails(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('b', 5, 'INVALID')))]);

        $this->advance();

        $this->assertSame('Apple marked build 5 (version 1.2.0) as INVALID.', $this->fresh()->last_error);
    }

    public function test_failed_processing_state_fails(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('b', 5, 'FAILED')))]);

        $this->advance();

        $this->assertSame('Apple marked build 5 (version 1.2.0) as FAILED.', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/cancel'));
    }

    public function test_apple_timeout_fails_and_alerts(): void // R11
    {
        $this->processing(['run_completed_at' => now()->subMinutes(91)->toIso8601String()]);
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('b', 5, 'PROCESSING')))]);

        $this->advance();

        $this->assertStringContainsString('within 90 minutes', $this->fresh()->last_error);
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK);
    }

    public function test_failure_text_is_redacted(): void // Review Focus 4; ett permanent fel (4xx) fäller direkt
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(['message' => 'bad token ghs_abcdefghijklmnopqrstuvwxyz0123456789'], 403)]);

        $this->advance();

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringNotContainsString('ghs_abcdefghijklmnopqrstuvwxyz0123456789', $this->fresh()->last_error);
        $this->assertStringContainsString('returned 403', $this->fresh()->last_error);
        // R15: körningen kan leva vidare och avbryts.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/runs/4711/cancel'));
    }

    public function test_internal_error_shows_only_a_reference(): void
    {
        $this->building(['github_run_id' => 'inte-ett-tal']);
        $this->fake();

        $this->advance();

        $this->assertMatchesRegularExpression('/^Internal error \(ref [0-9a-f-]{36}\)\.$/', $this->fresh()->last_error);
    }

    public function test_tag_created_in_between_on_the_same_commit_is_accepted(): void // krav från Task 6
    {
        $this->fake([
            self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(F::githubTagRef('v1.2.0', $this->sha('a'))),
            self::GAME_REPO.'/git/refs' => Http::response(['message' => 'Reference already exists'], 422),
        ]);

        $this->assertSame(30, $this->advance());

        $this->assertSame([Release::BUILDING, 4711], [$this->fresh()->status, $this->fresh()->external['github_run_id']]);
    }

    public function test_tag_created_in_between_on_another_commit_fails_without_dispatch(): void
    {
        $this->fake([
            self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(F::githubTagRef('v1.2.0', $this->sha('f'))),
            self::GAME_REPO.'/git/refs' => Http::response(['message' => 'Reference already exists'], 422),
        ]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('Tag v1.2.0 now points at another commit', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
    }

    public function test_annotated_tag_fails_without_dispatch_even_on_the_same_commit(): void
    {
        $this->fake([self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', $this->sha('a'), 'tag'))]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('Tag v1.2.0 is an annotated tag', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::GAME_REPO.'/git/refs' || str_ends_with($r->url(), '/dispatches'));
    }

    public function test_tag_422_without_an_existing_tag_fails_loudly(): void
    {
        $this->fake([self::GAME_REPO.'/git/refs' => Http::response(['message' => 'Object does not exist'], 422)]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('returned 422', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
    }

    public function test_invalid_marketing_version_is_never_built(): void // Task 1: kommatecken = OR i Apples filter
    {
        $this->release->update(['marketing_version' => '1.2.0,1.3.0']);
        $this->fake();

        $this->assertNull($this->advance());

        $this->assertStringContainsString('not a valid version', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'github.com/repos'));
    }

    public function test_invalid_marketing_version_is_never_sent_to_apple(): void
    {
        $this->processing();
        $this->release->update(['marketing_version' => '1.2.0,1.3.0']);
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('build-uuid-1', 5, 'VALID')))]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('not a valid version', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/builds'));
    }

    public function test_dispatch_marker_already_set_by_another_worker_is_not_dispatched_again(): void // R15
    {
        // I besittning utan markör när jobbet läste raden; en annan körning hann sätta markören.
        $this->release->update(['status' => Release::BUILDING, 'external' => ['claimed_at' => now()->toIso8601String()]]);
        $stale = Release::with('game')->find($this->release->id);
        Release::whereKey($this->release->id)->update(['external' => json_encode(['claimed_at' => now()->toIso8601String(), 'tag' => 'v1.2.0', 'dispatch_requested_at' => now()->toIso8601String()])]);
        $this->fake([self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', $this->sha('a')))]);

        $this->assertSame(15, app(TestFlightBuilder::class)->advance($stale));

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
    }

    public function test_success_log_has_commit_and_workflow_sha_but_no_tester_email(): void
    {
        Log::spy();
        $this->processing(['workflow_sha' => str_repeat('9', 40)]);
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('build-uuid-1', 5, 'VALID')))]);

        $this->advance();

        Log::shouldHaveReceived('notice')->withArgs(fn ($msg, $ctx = []) => $msg === 'TestFlight-bygge klart'
            && $ctx['commit'] === $this->sha('a') && $ctx['workflow_sha'] === str_repeat('9', 40)
            && ! str_contains(json_encode($ctx), '@'));
    }

    public function test_release_failed_while_dispatching_stays_failed_and_its_run_is_cancelled(): void // R15
    {
        $release = $this->release;
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => function () use ($release) {
            // failed() i kön fäller släppet medan dispatchen pågår.
            Release::whereKey($release->id)->update(['status' => Release::FAILED, 'active_key' => null, 'last_error' => 'The release job did not finish in time (queue timeout or retry window).']);

            return Http::response(F::githubDispatch(4711));
        }]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/runs/4711/cancel'));
    }

    public function test_release_failed_while_polling_is_not_moved_on(): void
    {
        $this->building();
        $release = $this->release;
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => function () use ($release) {
            Release::whereKey($release->id)->update(['status' => Release::FAILED, 'active_key' => null]);

            return Http::response(F::githubRun(4711, 'completed', 'success', $release->uuid));
        }]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
    }

    // Fix round 1: tillfälliga fel (0, 408, 429, 5xx) i pollningen fäller inte; fasgränsen ger felet.

    public function test_github_5xx_blip_then_success_moves_on(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::sequence()
            ->push(['message' => 'Server Error'], 502)
            ->push(F::githubRun(4711, 'completed', 'success', $this->release->uuid))]);

        $this->assertSame(30, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
        $this->assertNull($this->fresh()->last_error);

        $this->assertSame(120, $this->advance());
        $this->assertSame(Release::PROCESSING, $this->fresh()->status);
    }

    public function test_github_429_is_tolerated(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(['message' => 'rate limited'], 429)]);

        $this->assertSame(30, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
    }

    public function test_github_connection_failure_is_tolerated(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => fn () => throw new ConnectionException('reset')]);

        $this->assertSame(30, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
    }

    public function test_persistent_github_5xx_until_the_limit_fails_with_the_reason(): void
    {
        $this->building(['dispatched_at' => now()->subMinutes(91)->toIso8601String()]);
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(['message' => 'Server Error'], 502)]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('within 90 minutes', $this->fresh()->last_error);
        $this->assertStringContainsString('returned 502', $this->fresh()->last_error);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/runs/4711/cancel'));
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK);
    }

    public function test_run_lookup_5xx_during_recovery_is_tolerated(): void
    {
        $this->dispatchPending(5);
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['message' => 'Server Error'], 503)]);

        $this->assertSame(15, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
        $this->assertNull($this->fresh()->last_error);
    }

    public function test_apple_5xx_blip_then_valid_build_succeeds(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::sequence()
            ->push(A::error(503, 'SERVICE_UNAVAILABLE', 'try later'), 503)
            ->push(A::many(A::build('build-uuid-1', 5, 'VALID')))
            ->push(A::many(A::build('build-uuid-1', 5, 'VALID')))]);

        $this->assertSame(120, $this->advance());
        $this->assertSame(Release::PROCESSING, $this->fresh()->status);

        $this->assertNull($this->advance());
        $this->assertSame(Release::SUCCEEDED, $this->fresh()->status);
    }

    public function test_persistent_apple_5xx_until_the_limit_fails_with_the_reason(): void
    {
        $this->processing(['run_completed_at' => now()->subMinutes(91)->toIso8601String()]);
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::error(500, 'UNEXPECTED_ERROR', 'boom'), 500)]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('within 90 minutes', $this->fresh()->last_error);
        $this->assertStringContainsString('returned 500', $this->fresh()->last_error);
    }

    public function test_apple_4xx_fails_at_once(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::error(403, 'FORBIDDEN_ERROR', 'no access'), 403)]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('returned 403', $this->fresh()->last_error);
    }

    public function test_cancel_that_fails_when_the_release_fails_is_retried_by_the_sweep(): void
    {
        Queue::fake();
        $this->dispatchPending(1);
        // Första uppslaget (när släppet fälls) svarar 502; nästa (svepet) hittar körningen.
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::sequence()
            ->push(['message' => 'Server Error'], 502)
            ->push(['total_count' => 1, 'workflow_runs' => [F::githubRun(4712, 'in_progress', null, $this->release->uuid)]])]);

        app(TestFlightBuilder::class)->fail(Release::with('game')->find($this->release->id), new ReleaseFailed('Stopped.'));

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertTrue($this->fresh()->external['cancel_pending']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/cancel'));

        $this->artisan('computercat:advance-releases')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/runs/4712/cancel'));
        $this->assertArrayNotHasKey('cancel_pending', $this->fresh()->external);
        $this->assertArrayHasKey('cancel_confirmed_at', $this->fresh()->external);
        $this->assertSame(Release::FAILED, $this->fresh()->status);
    }

    public function test_cancel_retries_stop_and_alert_after_the_limit(): void
    {
        Queue::fake();
        $this->release->update(['status' => Release::FAILED, 'last_error' => 'Stopped.', 'external' => [
            'dispatch_requested_at' => now()->subMinutes(70)->toIso8601String(), 'cancel_pending' => true, 'cancel_attempts' => (int) config('releases.cancel_retries') - 1,
        ]]);
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['message' => 'Server Error'], 502)]);

        $this->artisan('computercat:advance-releases')->assertSuccessful();

        $this->assertArrayNotHasKey('cancel_pending', $this->fresh()->external);
        $this->assertArrayHasKey('cancel_given_up_at', $this->fresh()->external);
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK && str_contains($r['text'], 'kunde inte avbrytas'));

        // Inga fler försök efter det.
        $this->artisan('computercat:advance-releases')->assertSuccessful();
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/runs?')));
    }

    public function test_fail_on_a_finished_release_changes_nothing(): void
    {
        $this->release->update(['status' => Release::SUCCEEDED]);
        $this->fake();

        app(TestFlightBuilder::class)->fail(Release::with('game')->find($this->release->id), new ReleaseFailed('late'));

        $this->assertSame(Release::SUCCEEDED, $this->fresh()->status);
        $this->assertNull($this->fresh()->last_error);
        Http::assertNothingSent();
    }
}
