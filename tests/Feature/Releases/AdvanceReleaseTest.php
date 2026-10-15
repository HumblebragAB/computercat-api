<?php

namespace Tests\Feature\Releases;

use App\Jobs\AdvanceRelease;
use App\Models\Release;
use App\Releases\ReleaseService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class AdvanceReleaseTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private function makeTestFlightRelease(string $status, array $attrs = []): Release
    {
        $game = $this->testFlightGame();

        return $this->release($game, $attrs + ['target' => 'app_store', 'stage' => 'testflight', 'status' => $status,
            'marketing_version' => '1.0.0', 'build_number' => 1, 'build_guide' => 'v3']);
    }

    public function test_promote_queues_the_job_after_commit(): void
    {
        Queue::fake();
        F::configure();
        $this->iosGuides('v3');
        $game = $this->testFlightGame();
        $this->demoBuild($game, $this->sha('a'));
        $base = F::GITHUB.'/repos/HumblebragAB/kattsvep';
        Http::fake([
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            $base.'/contents/package.json?ref=*' => Http::response(F::githubContent('{"version":"1.0.0"}')),
            $base.'/contents/CLAUDE.md?ref=*' => Http::response(F::githubContent("Byggguide: **v3**.\n")),
            $base.'/git/ref/tags/*' => Http::response([], 404),
        ]);

        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'app_store', 'testflight', null);

        Queue::assertPushed(AdvanceRelease::class, fn (AdvanceRelease $job) => $job->releaseId === $release->id);
    }

    public function test_play_internal_queues_nothing(): void
    {
        Queue::fake();
        $game = $this->releasableGame('kattsvep', ['play_store']);
        $this->demoBuild($game);

        app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'play_store', 'play_internal', null);

        Queue::assertNothingPushed();
    }

    public function test_failed_handler_marks_the_release_failed_and_cancels_its_run(): void // Review Focus 3, R15
    {
        F::configure();
        Http::preventStrayRequests();
        Http::fake([
            F::SLACK => Http::response('ok'),
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            F::GITHUB.'/repos/HumblebragAB/computercat-release/actions/runs/4711/cancel' => Http::response('', 202),
        ]);
        $release = $this->makeTestFlightRelease(Release::BUILDING, ['external' => ['claimed_at' => now()->toIso8601String(), 'github_run_id' => 4711, 'dispatched_at' => now()->toIso8601String()]]);

        (new AdvanceRelease($release->id))->failed(new \RuntimeException('retry window'));

        $this->assertSame(Release::FAILED, $release->fresh()->status);
        $this->assertStringContainsString('did not finish in time', $release->fresh()->last_error);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runs/4711/cancel'));
    }

    public function test_finished_release_is_left_alone(): void
    {
        Http::fake();
        $release = $this->makeTestFlightRelease(Release::SUCCEEDED);

        app()->call([new AdvanceRelease($release->id), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(Release::SUCCEEDED, $release->fresh()->status);
    }

    public function test_sweep_requeues_active_testflight_releases(): void // R11
    {
        Queue::fake();
        $active = $this->makeTestFlightRelease(Release::PROCESSING);
        $this->release($active->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 2]);

        $this->artisan('computercat:advance-releases')->assertSuccessful();

        Queue::assertPushed(AdvanceRelease::class, 1);
        Queue::assertPushed(AdvanceRelease::class, fn (AdvanceRelease $job) => $job->releaseId === $active->id);
    }

    public function test_job_is_unique_per_release(): void
    {
        $job = new AdvanceRelease(42);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('release-42', $job->uniqueId());
    }
}
