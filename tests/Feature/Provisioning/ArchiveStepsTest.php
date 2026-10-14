<?php

namespace Tests\Feature\Provisioning;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\CoolifyDeleteStep;
use App\Provisioning\Steps\GameDeactivateStep;
use App\Provisioning\Steps\RepoArchiveStep;
use App\Provisioning\Steps\SiteOpsDeactivateStep;
use App\Support\SlackAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProvisioningFakes;

class ArchiveStepsTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private Game $game;

    private ProvisioningRun $provision;

    private ProvisioningRun $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'kattsvep', 'name' => 'K', 'is_active' => true, 'is_listed' => false, 'settings' => []]);
        $copy = ['game_id' => $this->game->id, 'github_repo' => 'HumblebragAB/kattsvep', 'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => 'https://kattsvep.apps.humblebrag.se'];
        $this->provision = $this->provisioningRun(['slug' => 'kattsvep', 'status' => ProvisioningRun::SUCCEEDED] + $copy);
        $this->archive = $this->provisioningRun(['slug' => 'kattsvep', 'kind' => ProvisioningRun::KIND_ARCHIVE, 'idea' => null] + $copy);
    }

    public function test_coolify_delete_requests_then_waits_until_gone(): void
    {
        Http::fake([
            self::PROJECT_URL => Http::sequence()
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')]))
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')]))
                ->push(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyDeleteQueued()),
        ]);

        $this->assertSame([StepResult::WAIT, 30], [($a = $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete'))->outcome, $a->seconds]);
        $this->assertSame(StepResult::WAIT, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
    }

    // Ett DELETE som Coolify avvisade (felstatus) begärdes inte: markören tas
    // bort, så att omkörningen begär raderingen igen i stället för att polla
    // en app som aldrig ska försvinna.
    public function test_rejected_delete_request_is_requested_again(): void
    {
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1' => Http::sequence()
                ->push(['message' => 'Server Error'], 500)
                ->push(ProvisioningFakes::coolifyDeleteQueued()),
        ]);

        try {
            $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete');
            $this->fail('500 på DELETE gav inget fel.');
        } catch (\App\Provisioning\Http\ExternalApiException) {
            // väntat
        }
        $this->assertArrayNotHasKey('delete_requested', $this->stepRow($this->archive, 'coolify_delete')->fresh()->detail ?? []);

        $this->assertSame(StepResult::WAIT, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
    }

    public function test_guard_failure_stops_archive_without_delete(): void
    {
        Queue::fake();
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-9', 'annan-app')])),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ]);
        Saga::start($this->archive);

        (new AdvanceProvisioningRun($this->archive->id))->withFakeQueueInteractions()->handle(app(SlackAlert::class));

        $this->assertSame(ProvisioningRun::FAILED, $this->archive->fresh()->status);
        $this->assertStringContainsString('is not in the Demo project', $this->archive->fresh()->last_error);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_coolify_delete_without_recorded_app_is_skipped(): void
    {
        $this->archive->update(['coolify_app_uuid' => null]);
        Http::fake();

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        Http::assertNothingSent();
    }

    public function test_siteops_is_deactivated(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/deactivate' => Http::response(ProvisioningFakes::siteopsDeactivated(42))]);

        $this->runStep(SiteOpsDeactivateStep::class, $this->archive, 'siteops_deactivate');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://siteops.test/api/v1/sites/42/deactivate');
    }

    public function test_repo_is_archived_never_deleted(): void
    {
        Http::fake([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::sequence()
                ->push(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid))
                ->push(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid, archived: true))
                ->push(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid, archived: true)),
        ]);

        $this->runStep(RepoArchiveStep::class, $this->archive, 'repo_archive');
        $this->runStep(RepoArchiveStep::class, $this->archive, 'repo_archive');

        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH' && $r->data() === ['archived' => true]));
    }

    public function test_missing_repo_fails_loudly(): void
    {
        Http::fake([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->expectException(StepFailed::class);
        $this->runStep(RepoArchiveStep::class, $this->archive, 'repo_archive');
    }

    public function test_game_is_deactivated_and_unlisted(): void
    {
        $this->runStep(GameDeactivateStep::class, $this->archive, 'game_deactivate');

        $this->assertFalse($this->game->fresh()->is_active);
        $this->assertFalse($this->game->fresh()->is_listed);
    }

    public function test_whole_archive_saga_marks_provision_run_archived(): void
    {
        Queue::fake();
        Http::fake([
            self::PROJECT_URL => Http::sequence()
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')]))
                ->push(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyDeleteQueued()),
            'siteops.test/api/v1/sites/42/deactivate' => Http::response(ProvisioningFakes::siteopsDeactivated(42)),
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid)),
        ]);
        Saga::start($this->archive);

        for ($i = 0; $i < 8 && $this->archive->fresh()->status === ProvisioningRun::RUNNING; $i++) {
            (new AdvanceProvisioningRun($this->archive->id))->withFakeQueueInteractions()->handle(app(SlackAlert::class));
        }

        $this->assertSame(ProvisioningRun::SUCCEEDED, $this->archive->fresh()->status);
        $this->assertSame(ProvisioningRun::ARCHIVED, $this->provision->fresh()->status);
        $this->assertSame([ProvisioningStep::DONE], $this->archive->steps()->distinct()->pluck('status')->all());
    }
}
