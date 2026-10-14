<?php

namespace Tests\Feature\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\CoolifyGuardException;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\DemoBuildRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Provisioning\ProvisioningTestCase;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;

class DemoBuildRecorderTest extends ProvisioningTestCase
{
    use CreatesReleases;

    private const LIST_URL = 'coolify.test/api/v1/deployments/applications/app-1*';

    private Game $game;

    private ProvisioningRun $run;

    // Ett enda Http::fake i setUp (ett andra efter setUp ignoreras: första träffen vinner). Testerna styr
    // svaren genom egenskaperna; Http::swap används inte eftersom det tar bort transaktionsvaktens middleware.
    private array $listBody = [];

    private int $app2Status = 500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'settings' => []]);
        $this->run = $this->provisioningRun([
            'slug' => 'kattsvep', 'game_id' => $this->game->id, 'status' => ProvisioningRun::SUCCEEDED,
            'coolify_app_uuid' => 'app-1', 'demo_url' => 'https://kattsvep.apps.humblebrag.se',
        ]);
        Http::fake([
            self::LIST_URL => fn () => Http::response($this->listBody),
            'coolify.test/api/v1/deployments/applications/app-2*' => fn () => Http::response(['message' => 'boom'], $this->app2Status),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ]);
    }

    private function fakeList(array $rows): void
    {
        $this->listBody = ProvisioningFakes::coolifyDeployments($rows);
    }

    private function recorder(): DemoBuildRecorder
    {
        return app(DemoBuildRecorder::class);
    }

    public function test_client_returns_commit_and_validates_its_type(): void
    {
        $this->fakeList([['dep-2', 'finished', '2026-10-14T09:00:00.000000Z', $this->sha('b')], ['dep-1', 'in_progress'], ['dep-0', 'some-future-status']]);

        $rows = app(CoolifyClient::class)->recentDeployments('app-1', 20);

        $this->assertSame(['deployment_uuid' => 'dep-2', 'status' => 'finished', 'created_at' => '2026-10-14T09:00:00.000000Z', 'commit' => $this->sha('b')], $rows[0]);
        Http::assertSent(fn ($r) => $r->url() === 'https://coolify.test/api/v1/deployments/applications/app-1?skip=0&take=20');

        // Okända status på icke-finished-poster fäller inte läsningen (S28).
        $this->assertSame('some-future-status', $rows[2]['status']);
        $this->assertNull($rows[2]['commit']);

        // Ett finished-bygge med fel form fäller.
        $this->listBody = ['count' => 1, 'deployments' => [['deployment_uuid' => 'd', 'status' => 'finished', 'created_at' => 'x', 'commit' => 123]]];
        $this->expectException(ExternalApiException::class);
        app(CoolifyClient::class)->recentDeployments('app-1');
    }

    public function test_client_refuses_unknown_uuid_without_a_request(): void
    {
        Http::fake();

        try {
            app(CoolifyClient::class)->recentDeployments('okand');
            $this->fail('Förväntade CoolifyGuardException.');
        } catch (CoolifyGuardException) {
            Http::assertNothingSent();
        }
    }

    public function test_only_finished_builds_with_a_full_sha_get_a_receipt(): void
    {
        $this->fakeList([
            ['dep-1', 'finished', '2026-10-14T08:00:00.000000Z', $this->sha('a')],
            ['dep-2', 'failed', '2026-10-14T08:10:00.000000Z', $this->sha('b')],
            ['dep-3', 'in_progress', '2026-10-14T08:20:00.000000Z', $this->sha('c')],
            ['dep-4', 'finished', '2026-10-14T08:30:00.000000Z', 'abc123'],
            ['dep-5', 'finished', '2026-10-14T08:40:00.000000Z', 'HEAD'],
        ]);

        $this->assertSame(1, $this->recorder()->recordForRun($this->run));

        $this->assertSame(['dep-1'], DemoBuild::pluck('deployment_uuid')->all());
        $this->assertSame($this->sha('a'), DemoBuild::first()->commit_sha);
        $this->assertSame($this->game->id, DemoBuild::first()->game_id);
    }

    public function test_recording_is_idempotent_and_normalizes_case(): void
    {
        $this->fakeList([['dep-1', 'finished', '2026-10-14T08:00:00.000000Z', strtoupper($this->sha('f'))]]);

        $this->assertSame(1, $this->recorder()->recordForRun($this->run));
        $this->assertSame(0, $this->recorder()->recordForRun($this->run));

        $this->assertSame(1, DemoBuild::count());
        $this->assertSame($this->sha('f'), DemoBuild::first()->commit_sha);
    }

    public function test_runs_that_are_not_succeeded_provisions_with_a_game_are_skipped(): void
    {
        $this->fakeList([['dep-1', 'finished']]);
        $this->run->update(['status' => ProvisioningRun::FAILED]);

        $this->assertSame(0, $this->recorder()->recordForRun($this->run));
        Http::assertNothingSent();
    }

    public function test_poll_command_records_for_all_games_and_reports_failures_once_per_day(): void
    {
        $other = Game::create(['slug' => 'ordjakt', 'name' => 'Ordjakt', 'is_active' => true, 'settings' => []]);
        $this->provisioningRun(['slug' => 'ordjakt', 'game_id' => $other->id, 'status' => ProvisioningRun::SUCCEEDED, 'coolify_app_uuid' => 'app-2', 'demo_url' => 'https://ordjakt.apps.humblebrag.se']);
        $this->fakeList([['dep-1', 'finished']]);

        $this->artisan('computercat:poll-demo-builds')->assertExitCode(1);
        $this->artisan('computercat:poll-demo-builds')->assertExitCode(1);

        $this->assertSame(1, DemoBuild::where('game_id', $this->game->id)->count());
        $this->assertSame(0, DemoBuild::where('game_id', $other->id)->count());
        $slack = Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK);
        $this->assertCount(1, $slack);
        $this->assertStringContainsString('ordjakt', $slack->first()[0]['text']);
    }

    public function test_poll_command_is_scheduled_every_five_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'computercat:poll-demo-builds'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
    }

    public function test_poll_command_refuses_when_provisioning_is_not_configured(): void
    {
        config(['provisioning.coolify.token' => '']);
        Http::fake();

        $this->artisan('computercat:poll-demo-builds')->expectsOutputToContain('COOLIFY_TOKEN')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
