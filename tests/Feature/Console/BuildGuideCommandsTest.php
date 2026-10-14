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

        $this->assertSame(['npm ci --ignore-scripts', 'npm run check'], $ran);
    }

    public function test_verify_rejects_unknown_version(): void
    {
        Process::fake();

        $this->artisan('computercat:byggguide:verify v9')->assertExitCode(1);

        Process::assertNothingRan();
    }

    // npm ci kör installationsskript från hundratals paket. De får aldrig se
    // computercat-api:s hemligheter (APP_KEY, Forge-, Coolify-, GitHub-tokens).
    public function test_verify_runs_npm_with_a_minimal_environment(): void
    {
        $forgeBefore = getenv('FORGE_API_TOKEN');
        $_ENV['GUIDE_TEST_SECRET_TOKEN'] = $_SERVER['GUIDE_TEST_SECRET_TOKEN'] = 'hemligt-varde-1';
        // Värdet byggs vid körning (ingen tokenliknande literal i repot, gitleaks).
        putenv('FORGE_API_TOKEN='.str_repeat('x', 12));
        $environments = [];
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$environments, &$commands) {
            $environments[] = $process->environment;
            $commands[] = $process->command;

            return Process::result();
        });

        try {
            $this->artisan('computercat:byggguide:verify v1')->assertExitCode(0);
        } finally {
            unset($_ENV['GUIDE_TEST_SECRET_TOKEN'], $_SERVER['GUIDE_TEST_SECRET_TOKEN']);
            putenv($forgeBefore === false ? 'FORGE_API_TOKEN' : "FORGE_API_TOKEN={$forgeBefore}");
        }

        $this->assertNotEmpty($environments);
        $allowed = [...VerifyBuildGuide::INHERITED_ENV, 'HOME', 'NEXT_TELEMETRY_DISABLED', 'NEXT_PUBLIC_COMPUTERCAT_GAME'];
        foreach ($environments as $env) {
            $kept = array_keys(array_filter($env, fn ($value) => $value !== false));
            $this->assertSame([], array_values(array_diff($kept, $allowed)), 'ärvda variabler som inte är tillåtna');
            foreach ($kept as $key) {
                $this->assertDoesNotMatchRegularExpression('/KEY|TOKEN|SECRET|PASSWORD/i', $key);
            }
            // Ärvda hemligheter tas bort uttryckligen (false), annars ärver processen dem.
            $this->assertFalse($env['GUIDE_TEST_SECRET_TOKEN']);
            $this->assertFalse($env['FORGE_API_TOKEN']);
            if (array_key_exists('APP_KEY', $_ENV) || getenv('APP_KEY') !== false) {
                $this->assertFalse($env['APP_KEY']);
            }
        }
        // Installationsskript körs inte under verifieringen.
        $this->assertSame('npm ci --ignore-scripts', $commands[0]);
    }

    // HOME pekar på en ny tillfällig katalog: npm och paketen når aldrig
    // ~/.npmrc, ~/.ssh eller annat i den riktiga hemkatalogen.
    public function test_verify_runs_with_a_temporary_home_that_is_removed(): void
    {
        $homes = [];
        Process::fake(function (PendingProcess $process) use (&$homes) {
            $homes[] = $process->environment['HOME'] ?? null;
            $this->assertDirectoryExists($process->environment['HOME']);

            return Process::result();
        });

        $this->artisan('computercat:byggguide:verify v1')->assertExitCode(0);

        $this->assertCount(1, array_unique($homes));
        $this->assertNotSame(getenv('HOME'), $homes[0]);
        $this->assertStringStartsWith(sys_get_temp_dir().'/byggguide-home-', $homes[0]);
        $this->assertDirectoryDoesNotExist($homes[0]);
        $this->assertNotContains('HOME', VerifyBuildGuide::INHERITED_ENV);
    }
}
