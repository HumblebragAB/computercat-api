<?php

namespace Tests\Feature\Provisioning;

use App\Models\Game;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\CoolifyAppStep;
use App\Provisioning\Steps\CoolifyEnvStep;
use App\Provisioning\Steps\CorsStep;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class CoolifyStepsTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    public function test_app_is_created_and_uuid_saved(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyAppStep::class, $run, 'coolify_app')->outcome);
        $this->assertSame('app-1', $run->fresh()->coolify_app_uuid);
    }

    public function test_existing_app_is_adopted_only_after_this_run_requested_creation(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        $this->stepRow($run, 'coolify_app')->update(['detail' => ['create_requested_at' => now()->toIso8601String()]]);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-7', 'spel-kattsvep')]))]);

        $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');

        $this->assertSame('app-7', $run->fresh()->coolify_app_uuid);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_foreign_app_with_the_name_is_never_adopted(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-7', 'spel-kattsvep')]))]);

        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
            $this->fail('En främmande app adopterades.');
        } catch (StepFailed $e) {
            $this->assertStringContainsString('was not created by this run', $e->getMessage());
        }
        $this->assertNull($run->fresh()->coolify_app_uuid);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_saved_uuid_means_done_without_requests(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'coolify_app_uuid' => 'app-1']);
        Http::fake();

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyAppStep::class, $run, 'coolify_app')->outcome);
        Http::assertNothingSent();
    }

    public function test_domain_conflict_fails(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyConflict(), 409),
        ]);

        $this->expectException(\App\Provisioning\Clients\CoolifyConflictException::class);
        $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
    }

    public function test_definite_rejection_clears_the_create_marker_so_a_later_manual_app_is_not_adopted(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([
            self::PROJECT_URL => Http::sequence()
                ->push(ProvisioningFakes::coolifyProject([]))
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('handgjord', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(['message' => 'Validation failed'], 422),
        ]);

        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
            $this->fail('422 borde ha kastat.');
        } catch (\App\Provisioning\Http\ExternalApiException) {
        }
        $this->assertArrayNotHasKey('create_requested_at', $this->stepRow($run, 'coolify_app')->fresh()->detail ?? []);

        $this->expectException(StepFailed::class);
        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
        } finally {
            $this->assertNull($run->fresh()->coolify_app_uuid);
        }
    }

    public function test_conflict_clears_the_marker_but_server_errors_keep_it(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::sequence()
                ->push(ProvisioningFakes::coolifyConflict(), 409)
                ->push(['message' => 'boom'], 502),
        ]);

        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
        } catch (\App\Provisioning\Clients\CoolifyConflictException) {
        }
        $this->assertArrayNotHasKey('create_requested_at', $this->stepRow($run, 'coolify_app')->fresh()->detail ?? []);

        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
        } catch (\App\Provisioning\Http\ExternalApiException) {
        }
        $this->assertArrayHasKey('create_requested_at', $this->stepRow($run, 'coolify_app')->fresh()->detail);
    }

    public function test_preview_variable_with_the_same_key_is_ignored(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'coolify_app_uuid' => 'app-1']);
        $preview = ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'annat');
        $preview['is_preview'] = true;
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1/envs' => Http::sequence()
                ->push([$preview])
                ->push(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201),
        ]);

        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');

        // Bara preview-posten fanns: den räknas inte, så den riktiga skapas (POST) i stället för att PATCH:as.
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertCount(0, Http::recorded(fn ($r) => $r->method() === 'PATCH'));
    }

    public function test_build_variable_is_created_or_updated(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1/envs' => Http::sequence()
                ->push([])
                ->push(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201)
                ->push([ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', null)])
                ->push(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201)
                ->push([ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep')]),
        ]);

        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');
        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');
        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');

        // 1: saknas → POST. 2: finns, värdet dolt → PATCH. 3: finns med samma värde → inget.
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH'));
    }

    public function test_env_step_without_app_fails(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->expectException(StepFailed::class);
        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');
    }

    public function test_cors_adds_demo_origin_once(): void
    {
        $game = Game::create(['slug' => 'kattsvep', 'name' => 'K', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['https://annan.se']]);
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $game->id]);

        $this->runStep(CorsStep::class, $run, 'cors');
        $this->runStep(CorsStep::class, $run, 'cors');

        $this->assertSame(['https://annan.se', 'https://kattsvep.apps.humblebrag.se'], $game->fresh()->allowed_origins);
    }
}
