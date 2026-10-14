<?php

namespace App\Console\Commands;

use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// Skriver ut byggguiden som spelets Claude gör (varje fil, kontrollerad mot
// sha256, plus README ur mallen) och kör manifestets install- och kontrollkommandon.
// Kör npm med nätverk: bara lokalt, aldrig i produktion, aldrig i en request.
class VerifyBuildGuide extends Command
{
    public const SLUG = 'byggguide-verify';

    // Det enda som ärvs från computercat-api:s miljö. npm ci kör kod från
    // hundratals paket; APP_KEY, Forge-, Coolify- och GitHub-tokens får de aldrig se.
    public const INHERITED_ENV = ['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL', 'npm_config_cache', 'NPM_CONFIG_CACHE'];

    protected $signature = 'computercat:byggguide:verify {version? : Standard: senaste versionen} {--keep : Behåll den tillfälliga katalogen}';

    protected $description = 'Skriver ut byggguiden i en tillfällig katalog och kör npm ci och de obligatoriska kontrollerna (bara lokalt)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('computercat:byggguide:verify körs bara lokalt, aldrig i produktion.');

            return self::FAILURE;
        }

        $version = (string) ($this->argument('version') ?? BuildGuide::LATEST);
        try {
            $manifest = BuildGuide::manifest($version);
        } catch (BuildGuideNotFound $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = sys_get_temp_dir().'/byggguide-verify-'.Str::lower(Str::random(12));
        mkdir($dir, 0700);

        try {
            foreach ($manifest['files'] as $entry) {
                $file = BuildGuide::file($version, $entry['path']);
                $target = $dir.'/'.$file['path'];
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                file_put_contents($target, $file['content']);
                if (hash_file('sha256', $target) !== $file['sha256']) {
                    $this->error("{$file['path']} skrevs inte exakt.");

                    return self::FAILURE;
                }
            }
            file_put_contents($dir.'/README.md', BuildGuide::readme($version, 'Byggguide-verifiering'));

            $env = self::environment(['NEXT_TELEMETRY_DISABLED' => '1', 'NEXT_PUBLIC_COMPUTERCAT_GAME' => self::SLUG]);
            // Manifestets install är npm ci --ignore-scripts (BuildGuideTest vaktar):
            // installationsskript körs aldrig, varken här, i spelet eller i Demo-bygget.
            foreach ([$manifest['commands']['install'], ...array_column($manifest['required_checks'], 'command')] as $command) {
                $this->line("> {$command}");
                $result = Process::path($dir)->env($env)->timeout(900)->run($command, fn (string $type, string $output) => $this->output->write($output));
                if ($result->failed()) {
                    $this->error("{$command} misslyckades (exitkod {$result->exitCode()}).");

                    return self::FAILURE;
                }
            }

            $this->info("Byggguide {$version}: OK (".count($manifest['files']).' filer, '.implode(', ', array_column($manifest['required_checks'], 'name')).').');

            return self::SUCCESS;
        } finally {
            if ($this->option('keep')) {
                $this->line("Katalogen behålls: {$dir}");
            } else {
                // Bara katalogen som skapades ovan.
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * Minimal miljö för npm: allt ärvt sätts till false (Symfony Process tar då
     * bort det), utom INHERITED_ENV. Utan false ärver processen hela miljön.
     *
     * @param  array<string, string>  $set
     * @return array<string, string|false>
     */
    public static function environment(array $set): array
    {
        $inherited = array_unique(array_merge(
            array_keys(getenv()),
            array_keys($_ENV),
            array_keys(array_filter($_SERVER, 'is_string')),
        ));

        $env = array_fill_keys(array_values(array_diff($inherited, self::INHERITED_ENV)), false);
        foreach (self::INHERITED_ENV as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return array_merge($env, $set);
    }
}
