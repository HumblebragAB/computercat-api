<?php

namespace Tests\Feature\Provisioning;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\Redactor;
use App\Provisioning\Saga;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\CoolifyDeleteStep;
use App\Provisioning\Steps\GameStep;
use App\Provisioning\Steps\RepoStep;
use App\Provisioning\Steps\Step;
use App\Support\SlackAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProvisioningFakes;

class AdvanceProvisioningRunTest extends ProvisioningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
    }

    private function fakeStep(string $class, \Closure $behaviour): void
    {
        $this->app->bind($class, fn () => new class($behaviour) implements Step
        {
            public function __construct(private \Closure $behaviour) {}

            public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
            {
                return ($this->behaviour)($run, $step);
            }
        });
    }

    private function startedRun(array $attrs = []): ProvisioningRun
    {
        $run = $this->provisioningRun($attrs);
        Saga::start($run);

        return $run;
    }

    private function runJob(ProvisioningRun $run): AdvanceProvisioningRun
    {
        $job = (new AdvanceProvisioningRun($run->id))->withFakeQueueInteractions();
        $job->handle(app(SlackAlert::class));

        return $job;
    }

    private function stepStatus(ProvisioningRun $run, string $step): string
    {
        return $run->steps()->where('step', $step)->value('status');
    }

    public function test_start_creates_pending_steps_in_order_and_queues_job(): void
    {
        $run = $this->startedRun();

        $this->assertSame(array_keys(Saga::PROVISION_STEPS), $run->steps()->pluck('step')->all());
        $this->assertSame([ProvisioningStep::PENDING], $run->steps()->distinct()->pluck('status')->all());
        Queue::assertPushed(AdvanceProvisioningRun::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_done_marks_step_merges_detail_and_queues_next_job(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => StepResult::done(['repo_ok' => true]));

        $job = $this->runJob($run);

        $job->assertNotReleased();
        $this->assertSame(ProvisioningStep::DONE, $this->stepStatus($run, 'repo'));
        $this->assertSame(['repo_ok' => true], $run->steps()->where('step', 'repo')->first()->detail);
        $this->assertSame(1, $run->steps()->where('step', 'repo')->value('attempts'));
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_wait_releases_job_and_keeps_step_pending(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => StepResult::wait(10, ['readme_wait_started_at' => '2026-10-14T08:00:00+00:00']));

        $job = $this->runJob($run);

        $job->assertReleased(delay: 10);
        $this->assertSame(ProvisioningStep::PENDING, $this->stepStatus($run, 'repo'));
        $this->assertSame('2026-10-14T08:00:00+00:00', $run->steps()->where('step', 'repo')->first()->detail['readme_wait_started_at']);
        Queue::assertPushed(AdvanceProvisioningRun::class, 1);
    }

    public function test_failure_redacts_last_error_and_hides_internal_exceptions(): void
    {
        $run = $this->startedRun();
        $body = Redactor::body(json_encode(['message' => 'bad', 'http_basic_auth_password' => 'losen-x', 'logs' => 'token=abc']));
        $this->fakeStep(RepoStep::class, fn () => throw new ExternalApiException('Coolify', 'POST', '/applications/private-github-app', 422, $body));

        $job = $this->runJob($run);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $run->refresh();
        $this->assertSame(ProvisioningRun::FAILED, $run->status);
        $this->assertSame(ProvisioningStep::FAILED, $this->stepStatus($run, 'repo'));
        $this->assertStringStartsWith('repo: Coolify POST /applications/private-github-app returned 422', $run->last_error);
        $this->assertStringNotContainsString('losen-x', $run->last_error);
        $this->assertStringNotContainsString('token=abc', $run->last_error);
        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK && str_contains($r['text'], $run->slug));

        // Ett internt fel visas bara som referens.
        $other = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => throw new \RuntimeException('SQLSTATE[23000] hemlig detalj'));
        $this->runJob($other);

        $other->refresh();
        $this->assertMatchesRegularExpression('/^repo: Internal error \(ref [0-9a-f-]{36}\)\.$/', $other->last_error);
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_step_failed_message_is_shown(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => throw new StepFailed('README.md lacks the idea markers.'));

        $this->runJob($run);

        $this->assertSame('repo: README.md lacks the idea markers.', $run->fresh()->last_error);
    }

    public function test_release_slug_frees_the_slug(): void
    {
        $run = $this->startedRun(['slug' => 'kattsvep']);
        $this->fakeStep(RepoStep::class, fn () => StepResult::releaseSlug('Slug is taken on GitHub.'));

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(ProvisioningRun::RELEASED, $run->status);
        $this->assertNull($run->slug_lock);
        $this->assertSame('repo: Slug is taken on GitHub.', $run->last_error);
        $this->provisioningRun(['slug' => 'kattsvep']);
    }

    public function test_run_that_is_not_running_is_left_alone(): void
    {
        $run = $this->startedRun(['status' => ProvisioningRun::FAILED]);
        $this->fakeStep(RepoStep::class, fn () => $this->fail('Steget kördes för en run som inte är running.'));

        $this->runJob($run);

        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
    }

    public function test_all_steps_done_marks_succeeded(): void
    {
        $run = $this->startedRun();
        $run->steps()->update(['status' => ProvisioningStep::DONE]);

        $this->runJob($run);

        $this->assertSame(ProvisioningRun::SUCCEEDED, $run->fresh()->status);
    }

    public function test_finished_archive_run_marks_provision_run_archived(): void
    {
        $provision = $this->provisioningRun(['status' => ProvisioningRun::SUCCEEDED, 'game_id' => null, 'slug' => 'kattsvep']);
        $game = \App\Models\Game::create(['slug' => 'kattsvep', 'name' => 'K', 'is_active' => true, 'settings' => []]);
        $provision->update(['game_id' => $game->id]);
        $archive = $this->provisioningRun(['kind' => ProvisioningRun::KIND_ARCHIVE, 'slug' => 'kattsvep', 'game_id' => $game->id]);
        Saga::start($archive);
        $archive->steps()->update(['status' => ProvisioningStep::DONE]);

        $this->runJob($archive);

        $this->assertSame(ProvisioningRun::SUCCEEDED, $archive->fresh()->status);
        $this->assertSame(ProvisioningRun::ARCHIVED, $provision->fresh()->status);
        $this->assertSame(array_keys(Saga::ARCHIVE_STEPS), $archive->steps()->pluck('step')->all());
    }

    public function test_job_queue_settings_survive_release_and_never_retry_on_exceptions(): void
    {
        $job = new AdvanceProvisioningRun(1);

        // --tries=1 på workern skulle annars fälla jobbet vid andra release().
        $this->assertSame(0, $job->tries);
        $this->assertNotNull($job->retryUntil());
        // Längsta väntan i ett steg är Coolify-raderingen (arkivering) och README efter skapandet.
        $this->assertTrue($job->retryUntil()->greaterThan(now()->addMinutes(max(config('provisioning.coolify_delete_timeout_minutes'), config('provisioning.repo_ready_timeout_minutes')))));
        // Ett jobb som pågår längre än retry_after körs annars en gång till parallellt.
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        // Ett undantag som ändå når kön får inte köras om i en tät loop.
        $this->assertGreaterThanOrEqual(30, $job->backoff);

        // Ett undantag i ett steg når aldrig kön (som med tries = 0 skulle köra om för evigt).
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => throw new \Error('trasigt'));
        $this->runJob($run);
        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
    }

    public function test_failed_hook_marks_run_failed(): void
    {
        $run = $this->startedRun();

        (new AdvanceProvisioningRun($run->id))->failed(new \RuntimeException('timeout'));

        $run->refresh();
        $this->assertSame(ProvisioningRun::FAILED, $run->status);
        $this->assertSame('repo: the step did not finish in time (queue timeout or retry window).', $run->last_error);
        $this->assertSame(ProvisioningStep::FAILED, $this->stepStatus($run, 'repo'));
    }

    public function test_retry_resumes_failed_run_once(): void
    {
        $run = $this->startedRun();
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::DONE]);
        $run->steps()->where('step', 'game')->update(['status' => ProvisioningStep::FAILED]);
        $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => 'game: x']);

        $this->assertTrue(Saga::retry($run));
        $this->assertFalse(Saga::retry($run->fresh()));

        $run->refresh();
        $this->assertSame(ProvisioningRun::RUNNING, $run->status);
        $this->assertNull($run->last_error);
        $this->assertSame(ProvisioningStep::PENDING, $this->stepStatus($run, 'game'));
        $this->assertSame(ProvisioningStep::DONE, $this->stepStatus($run, 'repo'));
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    // Jobbet dör efter att repo markerats klart men innan nästa jobb köats: retry tar upp tråden vid game.
    public function test_job_killed_between_done_and_next_dispatch_is_recovered_by_retry(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => StepResult::done());
        $this->runJob($run);
        Queue::fake(); // nästa jobb "köades aldrig"
        (new AdvanceProvisioningRun($run->id))->failed(new \RuntimeException('killed'));
        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
        $this->assertSame(ProvisioningStep::DONE, $this->stepStatus($run, 'repo'));

        $ran = [];
        $this->fakeStep(GameStep::class, function () use (&$ran) {
            $ran[] = 'game';

            return StepResult::done();
        });
        $this->assertTrue(Saga::retry($run->fresh()));
        Queue::assertPushed(AdvanceProvisioningRun::class, 1);
        $this->runJob($run);

        $this->assertSame(['game'], $ran);
        $this->assertSame(ProvisioningStep::DONE, $this->stepStatus($run, 'game'));
        $this->assertSame(ProvisioningRun::RUNNING, $run->fresh()->status);
    }

    public function test_retry_clears_wait_timestamps_but_keeps_other_detail(): void
    {
        $run = $this->startedRun();
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::FAILED, 'detail' => ['repo_ok' => true, 'readme_wait_started_at' => '2026-01-01T00:00:00+00:00']]);
        $run->update(['status' => ProvisioningRun::FAILED]);

        Saga::retry($run);

        $detail = $run->steps()->where('step', 'repo')->first()->detail;
        $this->assertSame(['repo_ok' => true], $detail);
    }

    public function test_handlers_resolve_by_kind(): void
    {
        $this->assertSame(GameStep::class, Saga::PROVISION_STEPS['game']);
        $this->assertSame(CoolifyDeleteStep::class, Saga::ARCHIVE_STEPS['coolify_delete']);
        // Inget deploy-steg: repot är redo för kod efter siteops, och första bygget kommer från pushen.
        $this->assertSame(['repo', 'game', 'coolify_app', 'coolify_env', 'cors', 'siteops'], array_keys(Saga::PROVISION_STEPS));
        $this->assertSame(['coolify_delete', 'siteops_deactivate', 'repo_archive', 'game_deactivate'], array_keys(Saga::ARCHIVE_STEPS));
    }
}
