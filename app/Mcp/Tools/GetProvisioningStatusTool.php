<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Models\User;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\DemoMonitoring;
use App\Provisioning\DemoProbe;
use App\Provisioning\Http\Redactor;
use App\Provisioning\SafeMessage;
use App\Releases\DemoBuildRecorder;
use App\Services\BuildGuide;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

class GetProvisioningStatusTool extends GameTool
{
    // Spec delprojekt 3, "Status vid fel", ordagrant (svenska som släppflödets next-texter).
    public const DEPLOY_FAILED_NEXT = 'Bygget föll i Coolify. Kör `npm run check` lokalt (samma säkerhetskontroller som Coolify), slå upp felet med `search_facts` och pusha igen. Pekar felet på npm-registret: vänta och pusha igen.';

    // Ett avbrutet bygge är inget fel i koden; det ska inte skicka spelets Claude att leta fel.
    public const DEPLOY_CANCELLED_NEXT = 'Bygget avbröts i Coolify innan det blev klart. Pusha igen för att starta ett nytt bygge; Demo visar förra lyckade bygget tills dess.';

    protected string $name = 'get_provisioning_status';

    protected string $description = 'Show how provisioning (or archiving) of a game is going: each step with status, the repo URL, the Demo URL with its basic auth login, and the latest error. After a successful run, "demo" tells whether code has been pushed and built: awaiting_code (ready for code, follow CLAUDE.md step 1), building, live, deployed_not_answering or deploy_failed. Works for the creator even if the game has not been created yet.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $runs = ProvisioningRun::where('game_id', $game->id)->with('steps')->orderByDesc('id')->get();
        if ($runs->isEmpty()) {
            return Response::error('This game has no provisioning run.');
        }

        return $this->json($this->present($game->slug, $runs));
    }

    // Repo-steget kan falla innan spelet finns. Den som skapade runnen ska
    // ändå se varför; ingen annan får veta att runnen finns.
    protected function whenGameMissing(User $user, string $slug, array $args): ?Response
    {
        $run = ProvisioningRun::provision()->where('slug', $slug)->where('user_id', $user->id)->whereNull('game_id')->with('steps')->orderByDesc('id')->first();

        return $run === null ? null : $this->json($this->present($slug, collect([$run])));
    }

    /** @param Collection<int, ProvisioningRun> $runs nyast först */
    private function present(string $slug, Collection $runs): array
    {
        $provision = $runs->firstWhere('kind', ProvisioningRun::KIND_PROVISION);
        $archive = $runs->firstWhere('kind', ProvisioningRun::KIND_ARCHIVE);

        return [
            'slug' => $slug,
            'provisioning' => $this->runData($provision),
            'archive' => $this->runData($archive),
            'repo_url' => $provision?->github_repo ? 'https://github.com/'.$provision->github_repo : null,
            'demo_url' => $provision?->demo_url,
            // Konvention (demo/demo), inte en hemlighet; spelets Claude behöver den för att kontrollera Demo.
            'demo_basic_auth' => [
                'username' => (string) config('provisioning.demo.basic_auth_user'),
                'password' => (string) config('provisioning.demo.basic_auth_password'),
            ],
            'demo' => $this->demo($provision),
        ];
    }

    // Efter en lyckad run är repot redo för kod. Har spelets Claude pushat
    // grundkoden, och svarar Demo? Ett Coolify-fel visas, det döljs aldrig.
    private function demo(?ProvisioningRun $run): ?array
    {
        if ($run === null || $run->status !== ProvisioningRun::SUCCEEDED || $run->coolify_app_uuid === null) {
            return null;
        }

        try {
            $latest = app(CoolifyClient::class)->latestDeployment($run->coolify_app_uuid);
        } catch (\Throwable $e) {
            $ref = (string) Str::uuid();
            Log::warning('get_provisioning_status kunde inte läsa Coolify-byggen', ['ref' => $ref, 'run' => $run->uuid, 'exception' => $e]);

            return [
                'state' => 'unknown',
                'latest_deployment' => null,
                'url_status' => null,
                'monitoring' => $run->monitoring_enabled_at === null ? 'pending' : 'enabled',
                'error' => $e instanceof SafeMessage ? Redactor::limit(Redactor::scrubSecrets($e->getMessage())) : "Could not read Coolify deployments (ref {$ref}).",
            ];
        }

        if ($latest === null) {
            return [
                'state' => 'awaiting_code',
                'latest_deployment' => null,
                'url_status' => null,
                'monitoring' => $run->monitoring_enabled_at === null ? 'pending' : 'enabled',
                'next' => 'Ready for code: in the game repo, follow CLAUDE.md step 1 (get_build_guide, write the files, run the checks) and push to main. The push builds the Demo.',
            ];
        }

        $state = match ($latest['status']) {
            'finished' => 'live',
            'failed', 'cancelled-by-user' => 'deploy_failed',
            default => 'building',
        };
        // Även efter ett misslyckat bygge kan ett tidigare bygge svara; därför prövas adressen.
        $code = $state === 'building' ? null : DemoProbe::status((string) $run->demo_url);
        if ($state === 'live' && $code !== 200) {
            $state = 'deployed_not_answering';
        }

        $result = [
            'state' => $state,
            'latest_deployment' => ['status' => $latest['status'], 'created_at' => $latest['created_at']],
            'url_status' => $code,
        ];

        if ($state === 'deploy_failed') {
            try {
                if ($this->runsSecurityChecks($run)) {
                    $result['next'] = $latest['status'] === 'cancelled-by-user' ? self::DEPLOY_CANCELLED_NEXT : self::DEPLOY_FAILED_NEXT;
                }
            } catch (\Throwable $e) {
                // Synligt men inte fatalt: Demo-läget gäller ändå.
                $ref = (string) Str::uuid();
                Log::warning('get_provisioning_status kunde inte läsa byggguiden', ['ref' => $ref, 'run' => $run->uuid, 'exception' => $e]);
                $result['next_error'] = "Could not read the build guide for this game (ref {$ref}).";
            }
        }

        // Första gången Demo lever: SiteOps-övervakningen slås på (en gång).
        try {
            app(DemoMonitoring::class)->enableIfLive($run, $latest, $code);
        } catch (\Throwable $e) {
            $ref = (string) Str::uuid();
            Log::warning('get_provisioning_status kunde inte slå på SiteOps-övervakningen', ['ref' => $ref, 'run' => $run->uuid, 'exception' => $e]);
            $result['monitoring_error'] = $e instanceof SafeMessage ? Redactor::limit(Redactor::scrubSecrets($e->getMessage())) : "Could not enable SiteOps monitoring (ref {$ref}).";
        }
        $result['monitoring'] = $run->fresh()->monitoring_enabled_at === null ? 'pending' : 'enabled';

        // Spärr 1: lyckade byggen sparas som kvitton. Ett fel här ändrar inte Demo-läget, men döljs inte.
        try {
            app(DemoBuildRecorder::class)->recordForRun($run);
        } catch (\Throwable $e) {
            $ref = (string) Str::uuid();
            Log::warning('get_provisioning_status kunde inte skriva Demo-kvitton', ['ref' => $ref, 'run' => $run->uuid, 'exception' => $e]);
            $result['receipts_error'] = $e instanceof SafeMessage ? 'Could not record Demo build receipts: '.Redactor::limit(Redactor::scrubSecrets($e->getMessage())) : "Could not record Demo build receipts (ref {$ref}).";
        }

        return $result;
    }

    // Guideversionen som repo-steget frös. v1 eller saknat värde: dagens svar, utan next.
    private function runsSecurityChecks(ProvisioningRun $run): bool
    {
        $version = $run->steps->firstWhere('step', 'repo')?->detail['build_guide'] ?? null;

        return is_string($version) && BuildGuide::runsSecurityChecksInBuild($version);
    }

    private function runData(?ProvisioningRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'run' => $run->uuid,
            'status' => $run->status,
            'steps' => $run->steps->map(fn (ProvisioningStep $step) => [
                'step' => $step->step,
                'status' => $step->status,
                'attempts' => $step->attempts,
            ])->values()->all(),
            'last_error' => $run->last_error === null ? null : Redactor::limit(Redactor::scrubSecrets($run->last_error)),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }
}
