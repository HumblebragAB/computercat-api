<?php

namespace Tests\Feature\Console;

use App\Console\Commands\VerifyBuildGuide;
use App\Services\BuildGuide;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BuildGuideCommandsTest extends TestCase
{
    public function test_verify_refuses_in_production(): void
    {
        Process::fake();
        $this->app['env'] = 'production';

        $this->artisan('computercat:byggguide:verify')->expectsOutputToContain('aldrig i produktion')->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_verify_writes_every_file_and_runs_the_manifest_commands_in_order(): void
    {
        $ran = [];
        $checkedFiles = false;
        Process::fake(function (PendingProcess $process) use (&$ran, &$checkedFiles) {
            $ran[] = ['command' => $process->command, 'path' => $process->path, 'game' => $process->environment['NEXT_PUBLIC_COMPUTERCAT_GAME'] ?? null];
            if (! $checkedFiles) {
                // Före första kommandot: varje fil finns med rätt sha256, och README från mallen.
                foreach (BuildGuide::manifest('v1')['files'] as $entry) {
                    $this->assertSame($entry['sha256'], hash_file('sha256', $process->path.'/'.$entry['path']), $entry['path']);
                }
                $this->assertStringContainsString('<!-- computercat:idea:start -->', file_get_contents($process->path.'/README.md'));
                $checkedFiles = true;
            }

            return Process::result();
        });

        $this->artisan('computercat:byggguide:verify v1')->expectsOutputToContain('Byggguide v1: OK')->assertExitCode(0);

        $m = BuildGuide::manifest('v1');
        $this->assertSame([$m['commands']['install'], ...array_column($m['required_checks'], 'command')], array_column($ran, 'command'));
        $this->assertCount(1, array_unique(array_column($ran, 'path')));
        $this->assertSame([VerifyBuildGuide::SLUG], array_values(array_unique(array_column($ran, 'game'))));
        $this->assertTrue($checkedFiles);
        // Den tillfälliga katalogen är borta efteråt.
        $this->assertDirectoryDoesNotExist($ran[0]['path']);
    }

    public function test_verify_stops_at_the_first_failing_check(): void
    {
        $ran = [];
        Process::fake(function (PendingProcess $process) use (&$ran) {
            $ran[] = $process->command;

            return $process->command === 'npm run check' ? Process::result(output: '', errorOutput: 'lint failed', exitCode: 1) : Process::result();
        });

        $this->artisan('computercat:byggguide:verify')->expectsOutputToContain('npm run check misslyckades')->assertExitCode(1);

        $this->assertSame(['npm ci', 'npm run check'], $ran);
    }

    public function test_verify_rejects_unknown_version(): void
    {
        Process::fake();

        $this->artisan('computercat:byggguide:verify v9')->assertExitCode(1);

        Process::assertNothingRan();
    }
}
