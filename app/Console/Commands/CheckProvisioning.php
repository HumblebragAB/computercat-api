<?php

namespace App\Console\Commands;

use App\Models\ProvisioningRun;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\DemoMonitoring;
use App\Provisioning\DemoProbe;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\SafeMessage;
use App\Releases\DemoBuildRecorder;
use App\Support\SlackAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Nattkontrollen skriver också Demo-kvitton (spärr 1) och bevakar släppen (checkReleases, Task 10).
 *
 * Invarianten för provisioneringen (spec 2c): varje lyckad run har ett
 * oarkiverat repo, en Coolify-app spel-<slug> med basic auth i Demo-projektet,
 * en aktiv och övervakad SiteOps-sajt, och, när spelets Claude har pushat,
 * ett lyckat senaste bygge och en Demo-URL som svarar 200. Ett spel utan
 * bygge larmar först efter karenstiden (no_push_grace_days). Omvänt
 * har varje spel-*-app i Demo-projektet en run. Fastnade sagor larmar också,
 * eftersom en död kö-worker annars aldrig syns.
 */
class CheckProvisioning extends Command
{
    protected $signature = 'computercat:check-provisioning {--dry-run : Skriv ut avvikelser utan Slack-larm}';

    protected $description = 'Jämför lyckade provisioneringar mot GitHub, Coolify, SiteOps och Demo-adressen';

    /** @var array<string, string> nyckel => beskrivning */
    private array $deviations = [];

    private DemoMonitoring $monitoring;

    private DemoBuildRecorder $receipts;

    public function handle(GitHubAppClient $github, CoolifyClient $coolify, SiteOpsClient $siteops, SlackAlert $slack, DemoMonitoring $monitoring, DemoBuildRecorder $receipts): int
    {
        // Kommandoinstansen återanvänds inom en process (Artisan::call, tester):
        // avvikelser från en tidigare körning får inte följa med.
        $this->deviations = [];
        $this->monitoring = $monitoring;
        $this->receipts = $receipts;

        $missing = ProvisioningConfig::missing();
        if ($missing !== []) {
            $this->error('Provisioneringen är inte konfigurerad, saknas: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $apps = null;
        try {
            $apps = $coolify->demoApplications();
        } catch (Throwable $e) {
            $this->deviations['coolify:list'] = 'Kunde inte läsa Demo-projektet i Coolify: '.$this->describe($e);
        }

        foreach (ProvisioningRun::provision()->where('status', ProvisioningRun::SUCCEEDED)->orderBy('id')->get() as $run) {
            $this->checkRun($run, $apps, $github, $coolify, $siteops);
        }

        if ($apps !== null) {
            foreach ($apps as $app) {
                if (str_starts_with($app['name'], 'spel-') && ! ProvisioningRun::where('coolify_app_uuid', $app['uuid'])->exists()) {
                    $this->deviations["coolify:orphan:{$app['uuid']}"] = "Coolify-appen {$app['name']} ({$app['uuid']}) i Demo-projektet saknar provisioneringsrun.";
                }
            }
        }

        $this->checkStuck();

        foreach ($this->deviations as $key => $text) {
            $this->line("AVVIKELSE {$key}: {$text}");
            if (! $this->option('dry-run')) {
                $slack->send("check-provisioning:{$key}", 'computercat:check-provisioning: '.SlackAlert::escape($text));
            }
        }

        $this->info(count($this->deviations).' avvikelse(r).');

        return $this->deviations === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array{uuid: string, name: string}>|null $apps */
    private function checkRun(ProvisioningRun $run, ?array $apps, GitHubAppClient $github, CoolifyClient $coolify, SiteOpsClient $siteops): void
    {
        $key = "run:{$run->uuid}";

        $this->check("{$key}:repo", "repot för {$run->slug}", function () use ($github, $run) {
            $repo = $github->getRepository($run->slug);
            if ($repo === null) {
                return "Repot {$run->github_repo} finns inte.";
            }

            return ($repo['archived'] ?? null) === false ? null : "Repot {$run->github_repo} är arkiverat men runnen är succeeded.";
        });

        if ($apps !== null) {
            $listed = false;
            foreach ($apps as $app) {
                $listed = $listed || ($app['uuid'] === $run->coolify_app_uuid && $app['name'] === ProvisioningConfig::coolifyAppName($run->slug));
            }
            if (! $listed) {
                $this->deviations["{$key}:coolify"] = 'Coolify-appen '.ProvisioningConfig::coolifyAppName($run->slug)." ({$run->coolify_app_uuid}) finns inte i Demo-projektet.";
            } else {
                $this->check("{$key}:basic_auth", "basic auth för {$run->slug}", fn () => ($coolify->application((string) $run->coolify_app_uuid)['is_http_basic_auth_enabled'] ?? null) === true
                    ? null
                    : 'Coolify-appen '.ProvisioningConfig::coolifyAppName($run->slug).' har inte basic auth.');
            }
        }

        $this->check("{$key}:siteops", "SiteOps för {$run->slug}", function () use ($siteops, $run) {
            if ($run->siteops_site_id === null) {
                return 'Runnen har ingen SiteOps-sajt.';
            }
            $site = $siteops->site($run->siteops_site_id);
            if (($site['is_active'] ?? null) !== true) {
                return "SiteOps-sajten {$run->siteops_site_id} är inte aktiv.";
            }
            if (($site['monitoring_enabled'] ?? null) !== true) {
                return "SiteOps-sajten {$run->siteops_site_id} har övervakningen avstängd (monitoring_enabled).";
            }
            $want = SiteOpsClient::normalizeUrl((string) $run->demo_url);
            foreach ($siteops->environments($run->siteops_site_id) as $environment) {
                if (($environment['type'] ?? null) === 'demo' && SiteOpsClient::normalizeUrl((string) ($environment['url'] ?? '')) === $want) {
                    // Övervakningen slås på först när Demo lever (monitoring_enabled_at).
                    if ($run->monitoring_enabled_at !== null && ($environment['is_monitored'] ?? null) !== true) {
                        return "Demo-miljön i SiteOps-sajten {$run->siteops_site_id} övervakas inte.";
                    }

                    return ($environment['has_basic_auth'] ?? null) === true ? null : "Demo-miljön i SiteOps-sajten {$run->siteops_site_id} saknar basic auth (övervakningen får 401).";
                }
            }

            return "SiteOps-sajten {$run->siteops_site_id} har ingen miljö med {$run->demo_url}.";
        });

        $this->checkDeployment($key, $run, $coolify);

        // Spärr 1: nattkontrollen är det tredje stället som skriver Demo-kvitton (skrivs också med --dry-run, det är lokalt).
        $this->check("{$key}:receipts", "Demo-kvitton för {$run->slug}", function () use ($run) {
            $this->receipts->recordForRun($run);

            return null;
        });
    }

    // Repot är redo för kod efter sagan; första bygget kommer från spelets push.
    // Innan en lyckad deploy setts (monitoring_enabled_at saknas): tyst i
    // karenstiden, och lever Demo nu slås övervakningen på (inte med --dry-run).
    // Därefter: senaste bygget får inte ha fallerat, och Demo ska svara 200.
    private function checkDeployment(string $key, ProvisioningRun $run, CoolifyClient $coolify): void
    {
        try {
            $latest = $coolify->latestDeployment((string) $run->coolify_app_uuid);
        } catch (Throwable $e) {
            $this->deviations["{$key}:deploy"] = "Kunde inte läsa byggen för {$run->slug}: ".$this->describe($e);

            return;
        }

        if ($run->monitoring_enabled_at === null) {
            $code = ($latest['status'] ?? null) === 'finished' ? DemoProbe::status((string) $run->demo_url) : null;
            if ($code === 200) {
                if ($this->option('dry-run')) {
                    $this->line("{$run->slug}: Demo lever; övervakningen i SiteOps skulle slås på (--dry-run).");

                    return;
                }
                $this->check("{$key}:monitoring", "övervakningen för {$run->slug}", function () use ($run, $latest, $code) {
                    $this->monitoring->enableIfLive($run, $latest, $code);
                    $this->line("{$run->slug}: övervakningen i SiteOps påslagen.");

                    return null;
                });

                return;
            }

            $grace = (int) config('provisioning.no_push_grace_days');
            $seen = $latest === null ? 'inget bygge' : "senaste bygget {$latest['status']}".($code !== null ? ", Demo svarar {$code}" : '');
            if ($run->created_at->gt(now()->subDays($grace))) {
                $this->line("{$run->slug}: väntar på första lyckade deploy ({$seen}, skapad {$run->created_at->toDateString()}).");
            } else {
                $this->deviations["{$key}:not_live"] = "{$run->slug} har ingen lyckad deploy {$grace} dagar efter att repot skapades ({$seen}). Har spelets Claude pushat grundkoden (CLAUDE.md steg 1)?";
            }

            return;
        }

        if ($latest === null) {
            $this->deviations["{$key}:deploy"] = "{$run->slug} har övervakning påslagen men Coolify listar inga byggen.";

            return;
        }

        if (in_array($latest['status'], ['failed', 'cancelled-by-user'], true)) {
            $this->deviations["{$key}:deploy_failed"] = "Senaste bygget av {$run->slug} ({$latest['deployment_uuid']}) slutade med {$latest['status']}.";
        }

        $this->check("{$key}:demo", "Demo-adressen för {$run->slug}", function () use ($run) {
            $status = DemoProbe::status((string) $run->demo_url);

            return $status === 200 ? null : "{$run->demo_url} svarade {$status} med basic auth.";
        });
    }

    private function checkStuck(): void
    {
        $cutoff = now()->subMinutes(30);
        foreach (ProvisioningRun::where('status', ProvisioningRun::RUNNING)->get() as $run) {
            $last = $run->steps()->max('updated_at') ?? $run->updated_at;
            if (Carbon::parse($last)->lt($cutoff)) {
                $this->deviations["run:{$run->uuid}:stuck"] = "Runnen för {$run->slug} ({$run->kind}) har stått still sedan {$last}. Kör kö-workern?";
            }
        }

        // En run som står failed utan retry blir annars aldrig sedd.
        foreach (ProvisioningRun::where('status', ProvisioningRun::FAILED)->where('updated_at', '<', now()->subDay())->get() as $run) {
            $this->deviations["run:{$run->uuid}:failed"] = "Runnen för {$run->slug} ({$run->kind}) har stått som failed sedan {$run->updated_at} utan retry_provisioning.";
        }
    }

    /** @param \Closure(): ?string $check returnerar en avvikelse eller null */
    private function check(string $key, string $what, \Closure $check): void
    {
        try {
            $deviation = $check();
        } catch (Throwable $e) {
            $deviation = "Kunde inte kontrollera {$what}: ".$this->describe($e);
        }

        if ($deviation !== null) {
            $this->deviations[$key] = $deviation;
        }
    }

    private function describe(Throwable $e): string
    {
        return $e instanceof SafeMessage ? $e->getMessage() : get_class($e);
    }
}
