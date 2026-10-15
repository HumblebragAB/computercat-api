<?php

namespace App\Releases\TestFlight;

use App\Models\Release;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\SafeMessage;
use App\Releases\AppStore\AppStoreConnectApi;
use App\Releases\MarketingVersion;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Support\SlackAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Ett TestFlight-släpp från requested till succeeded eller failed (spec "Statusläsning", plan R11, R15, R16).
//
// Hotmodell (Task 7): i GitHub skrivs bara taggen v<version> i spelets repo (aldrig flyttad), en
// dispatch per släpp i släpprepot och cancel av släppets egen körning. I Apple länkas bara bygget till
// gruppen. Flödets indata kommer bara från Computercats egna kolumner. Släppet tas i besittning
// (requested -> building under radlås) före första skrivningen utåt, så att ett avbrutet släpp aldrig
// byggs och ett startat aldrig kan avbrytas i Computercat utan att körningen avbryts. Markören
// dispatch_requested_at sparas före dispatch; ett okänt utfall (5xx, bruten anslutning, inget
// körnings-id) leder till uppslag via run-name, aldrig till en andra körning. last_error och Slack får
// bara SafeMessage-texter; allt annat blir "Internal error (ref …)". Inga Actions-loggar läses.
// Taggen: finns den redan (eller skapas den samtidigt, createTag 422) godtas den bara som lättviktig
// tagg på samma commit; annan commit eller annoterad tagg fäller släppet. Versionen valideras med
// MarketingVersion innan den blir tagg, workflow-indata eller filter mot Apple (kommatecken = OR).
// Markören sätts under radlås: två samtidiga jobb kan aldrig båda nå dispatch.
//
// Tillstånd: requested -> building (claimed_at) -> building (dispatch_requested_at) ->
// building (github_run_id) -> processing (run_completed_at) -> succeeded. Varje steg kan bli failed.
final class TestFlightBuilder
{
    public function __construct(
        private GitHubAppClient $github,
        private AppStoreConnectApi $asc,
        private ReleaseService $releases,
        private SlackAlert $slack,
    ) {}

    public function handles(Release $release): bool
    {
        return $release->target === 'app_store' && $release->stage === 'testflight'
            && in_array($release->status, [Release::REQUESTED, Release::BUILDING, Release::PROCESSING], true);
    }

    /** @return int|null sekunder till nästa kontroll; null när släppet är klart, fällt eller inte längre vårt */
    public function advance(Release $release): ?int
    {
        try {
            $external = $release->external ?? [];

            return match (true) {
                $release->status === Release::REQUESTED => $this->start($release),
                $release->status === Release::BUILDING && isset($external['github_run_id']) => $this->followRun($release),
                $release->status === Release::BUILDING && isset($external['dispatch_requested_at']) => $this->recoverDispatch($release),
                $release->status === Release::BUILDING => $this->dispatch($release), // tagits i besittning, dispatch inte gjord
                $release->status === Release::PROCESSING => $this->followApple($release),
            };
        } catch (Throwable $e) {
            $this->fail($release, $e);

            return null;
        }
    }

    public function fail(Release $release, Throwable $e): void
    {
        $ref = (string) Str::uuid();
        $message = $e instanceof SafeMessage || $e instanceof ReleaseRefused ? $e->getMessage() : "Internal error (ref {$ref}).";
        Log::error('TestFlight-släpp misslyckades', ['ref' => $ref, 'release' => $release->uuid, 'exception' => $e]);

        // Under radlås och bara från en aktiv status: ett klart, fällt eller avbrutet släpp ändras aldrig.
        $fresh = DB::transaction(function () use ($release, $message) {
            $locked = Release::with('game')->whereKey($release->getKey())->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [Release::REQUESTED, Release::BUILDING, Release::PROCESSING], true)) {
                return null;
            }
            $locked->update(['status' => Release::FAILED, 'last_error' => $message]);

            return $locked;
        });
        if ($fresh === null) {
            return;
        }

        // R15: en körning som kan leva vidare avbryts, så att den inte laddar upp ett bygge för ett fällt släpp.
        // Går det inte nu (GitHub svarar inte) försöker svepet igen (retryCancel).
        if (! ($e instanceof ReleaseFailed && $e->runFinished) && ! $this->cancelRunOf($fresh)) {
            $this->markCancelPending($fresh);
        }

        $this->slack->send(
            "release-failed:{$fresh->uuid}",
            'TestFlight-släppet av `'.SlackAlert::escape($fresh->game->slug).'` version '.SlackAlert::escape((string) $fresh->marketing_version)
                .' (bygge '.SlackAlert::escape((string) $fresh->build_number).') föll: '.SlackAlert::escape((string) $fresh->last_error)." (släpp {$fresh->uuid}, ref {$ref}).",
        );
    }

    /**
     * Svepet (computercat:advance-releases) försöker avbryta körningen för ett fällt släpp där det inte
     * gick när släppet fälldes. Högst releases.cancel_retries försök; sedan larm och inga fler försök.
     */
    public function retryCancel(Release $release): void
    {
        $external = $release->external ?? [];
        if (! in_array($release->status, [Release::FAILED, Release::CANCELLED], true) || ($external['cancel_pending'] ?? false) !== true) {
            return;
        }

        if ($this->cancelRunOf($release)) {
            $this->updateExternal($release, fn (array $x) => array_merge(array_diff_key($x, ['cancel_pending' => 1]), ['cancel_confirmed_at' => now()->toIso8601String()]));

            return;
        }

        $attempts = (int) ($external['cancel_attempts'] ?? 1) + 1;
        if ($attempts < (int) config('releases.cancel_retries')) {
            $this->updateExternal($release, fn (array $x) => array_merge($x, ['cancel_attempts' => $attempts]));

            return;
        }

        $this->updateExternal($release, fn (array $x) => array_merge(array_diff_key($x, ['cancel_pending' => 1]), ['cancel_attempts' => $attempts, 'cancel_given_up_at' => now()->toIso8601String()]));
        Log::error('GitHub-körningen för ett fällt släpp kunde inte avbrytas', ['release' => $release->uuid, 'attempts' => $attempts]);
        $this->slack->send(
            "release-cancel-given-up:{$release->uuid}",
            'Körningen för det fällda TestFlight-släppet '.$release->uuid.' (`'.SlackAlert::escape((string) $release->game?->slug).'`) kunde inte avbrytas efter '.$attempts.' försök; avbryt den för hand i computercat-release under Actions.',
        );
    }

    private function markCancelPending(Release $release): void
    {
        $this->updateExternal($release, fn (array $x) => array_merge($x, ['cancel_pending' => true, 'cancel_attempts' => 1]));
        Log::warning('Körningen kunde inte avbrytas nu; svepet försöker igen', ['release' => $release->uuid]);
    }

    // Ändrar bara external, under radlås (status rörs inte).
    private function updateExternal(Release $release, \Closure $change): void
    {
        DB::transaction(function () use ($release, $change) {
            $locked = Release::whereKey($release->getKey())->lockForUpdate()->first();
            $locked?->update(['external' => $change($locked->external ?? [])]);
        });
        $release->refresh();
    }

    // Tillfälliga fel: ingen anslutning, timeout, rate limit och 5xx. De fäller inte släppet i pollningen;
    // fasgränsen gör det i stället (R11). Alla andra fel (4xx, oväntat svar) fäller direkt.
    private static function isTransient(Throwable $e): bool
    {
        return $e instanceof ExternalApiException && ($e->status === 0 || $e->status >= 500 || in_array($e->status, [408, 429], true));
    }

    /**
     * @param  bool  $overdue  fasgränsen är passerad
     * @return int poll-intervallet om felet är tillfälligt och gränsen inte passerad
     */
    private function tolerate(Release $release, ExternalApiException $e, bool $overdue, string $reason, int $poll, bool $runFinished = false): int
    {
        if (! self::isTransient($e)) {
            throw $e;
        }
        if ($overdue) {
            throw new ReleaseFailed("{$reason}; the last error was: ".$e->getMessage(), runFinished: $runFinished);
        }
        Log::warning('Tillfälligt fel i släppets pollning; försöker igen', ['release' => $release->uuid, 'status' => $e->status, 'error' => $e->getMessage()]);

        return $poll;
    }

    private function start(Release $release): ?int
    {
        // R14: rader från 1a saknar version och byggnummer; de byggs aldrig med gissade värden.
        if ($release->marketing_version === null || $release->build_number === null || $release->build_guide === null) {
            throw new ReleaseFailed('This release was registered before TestFlight builds existed; request a new one with promote_release.');
        }
        $this->version($release);

        // Spärr 7 och 9: samma kontroll som vid begäran, nu mot dagens läge.
        $this->releases->assertCanStart($release);

        $profileId = $release->game->appStoreId('profile_id') ?? throw new ReleaseFailed('The game has no App Store profile; ask Erik to run the setup steps in Filament.');
        $profile = $this->asc->profile($profileId);
        if ($profile === null || $profile['state'] !== 'ACTIVE' || Carbon::parse($profile['expires_at'])->lt(now()->addDay())) {
            throw new ReleaseFailed('The App Store provisioning profile is not active or expires within a day; Erik must renew it before the next TestFlight build.');
        }

        // R16: i besittning under radlås, före taggen och dispatchen. Avbröts släppet under tiden: inget görs.
        $claimed = DB::transaction(function () use ($release) {
            $locked = Release::whereKey($release->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== Release::REQUESTED) {
                return false;
            }
            $locked->update(['status' => Release::BUILDING, 'external' => array_merge($locked->external ?? [], ['claimed_at' => now()->toIso8601String()])]);

            return true;
        });
        if (! $claimed) {
            Log::notice('TestFlight-släpp ändrades före start; inget byggs', ['release' => $release->uuid]);

            return null;
        }

        return $this->dispatch($release->fresh(['game']));
    }

    private function dispatch(Release $release): ?int
    {
        $game = $release->game;
        $tag = 'v'.$this->version($release);
        $this->ensureTag($game->slug, $tag, (string) $release->commit_sha);

        // R15: markören sätts under radlås och bara en gång. Har ett annat jobb redan satt den (eller
        // släppet lämnat building) görs ingen dispatch här; körningen letas upp via run-name i stället.
        $marked = DB::transaction(function () use ($release, $tag) {
            $locked = Release::whereKey($release->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== Release::BUILDING) {
                return null;
            }
            if (isset(($locked->external ?? [])['dispatch_requested_at'])) {
                return false;
            }
            $locked->update(['external' => array_merge($locked->external ?? [], ['tag' => $tag, 'dispatch_requested_at' => now()->toIso8601String()])]);

            return true;
        });
        if ($marked !== true) {
            Log::notice('Dispatch redan begärd eller släppet ändrat; ingen ny dispatch', ['release' => $release->uuid]);

            return $marked === null ? null : 15;
        }
        $release->refresh();

        try {
            $run = $this->github->dispatchWorkflow(
                (string) config('releases.ios_workflow.repo'),
                (string) config('releases.ios_workflow.workflow'),
                (string) config('releases.ios_workflow.ref'),
                $this->inputs($release),
            );
        } catch (ExternalApiException $e) {
            // R15: bara 4xx betyder att GitHub inte tog emot begäran. 5xx, bruten anslutning (0) och ett
            // svar utan körnings-id kan ha startat en körning: den letas upp via run-name.
            if ($e->status >= 400 && $e->status < 500) {
                throw new ReleaseFailed('GitHub refused to start the release workflow: '.$e->getMessage(), runFinished: true);
            }
            Log::warning('Dispatch med okänt utfall; letar upp körningen via run-name', ['release' => $release->uuid, 'status' => $e->status]);

            return 15;
        }
        if (! $this->started($release, $run['run_id'], $run['html_url'])) {
            return null;
        }

        return (int) config('releases.poll_seconds.github');
    }

    // Taggen sätts före dispatch och flyttas eller raderas aldrig. 422 från createTag betyder oftast att
    // taggen skapades under tiden ("Reference already exists"): den läses om och prövas som en befintlig.
    private function ensureTag(string $slug, string $tag, string $commit): void
    {
        $existing = $this->github->getTag($slug, $tag);
        if ($existing === null) {
            try {
                $this->github->createTag($slug, $tag, $commit);

                return;
            } catch (ExternalApiException $e) {
                if ($e->status !== 422) {
                    throw $e;
                }
                // Ingen tagg efter en 422: något annat är fel, och felet ska synas som det är.
                $existing = $this->github->getTag($slug, $tag) ?? throw $e;
                Log::notice('Taggen fanns redan när den skulle skapas; prövas som befintlig', ['tag' => $tag, 'type' => $existing['type']]);
            }
        }

        if ($existing['type'] !== 'commit') {
            throw new ReleaseFailed("Tag {$tag} is an annotated tag; Computercat only builds from lightweight tags. Raise the version in package.json and request a new release.");
        }
        if ($existing['sha'] !== $commit) {
            throw new ReleaseFailed("Tag {$tag} now points at another commit; raise the version in package.json and request a new release.");
        }
    }

    // Task 1: versionen blir tagg, workflow-indata och filter mot Apple (där kommatecken betyder OR).
    private function version(Release $release): MarketingVersion
    {
        return MarketingVersion::parse($release->marketing_version)
            ?? throw new ReleaseFailed('The release version is not a valid version (MAJOR.MINOR.PATCH); request a new release with promote_release.', runFinished: $release->status === Release::PROCESSING);
    }

    private function recoverDispatch(Release $release): int
    {
        $since = Carbon::parse($release->external['dispatch_requested_at']);
        try {
            $run = $this->findRun($release);
        } catch (ExternalApiException $e) {
            $limit = (int) config('releases.timeouts.github_run_minutes');

            return $this->tolerate($release, $e, $since->lt(now()->subMinutes($limit)), "The release run could not be looked up within {$limit} minutes", 15);
        }
        if ($run !== null) {
            return $this->started($release, $run['id'], $run['html_url']) ? (int) config('releases.poll_seconds.github') : null;
        }

        $window = (int) config('releases.timeouts.dispatch_lookup_minutes');
        if ($since->lt(now()->subMinutes($window))) {
            throw new ReleaseFailed("The release workflow was requested but no run was found within {$window} minutes; check GitHub Actions in computercat-release and request a new release.");
        }

        return 15;
    }

    // false: släppet är inte längre i building (fällt eller avbrutet under tiden). Körningen avbryts då.
    private function started(Release $release, int $runId, string $url): bool
    {
        $moved = $this->moveIf($release, Release::BUILDING, Release::BUILDING, fn (array $external) => [
            'github_run_id' => $runId, 'github_run_url' => $url, 'dispatched_at' => $external['dispatch_requested_at'] ?? now()->toIso8601String(),
        ]);
        if (! $moved) {
            Log::warning('Körning startad för ett släpp som inte längre bygger; avbryts', ['release' => $release->uuid, 'run' => $runId]);
            $this->updateExternal($release, fn (array $x) => array_merge($x, ['github_run_id' => $runId]));
            if (! $this->cancelRunOf($release)) {
                $this->markCancelPending($release);
            }

            return false;
        }
        Log::notice('TestFlight-bygge startat', ['release' => $release->uuid, 'run' => $runId, 'commit' => $release->commit_sha, 'version' => $release->marketing_version, 'build' => $release->build_number]);

        return true;
    }

    /**
     * Fasbyte under radlås, bara om släppet fortfarande står i $from (ett parallellt failed() eller
     * avbrott vinner). $external får radens aktuella external och returnerar det som läggs till.
     */
    private function moveIf(Release $release, string $from, string $to, \Closure $external): bool
    {
        $moved = DB::transaction(function () use ($release, $from, $to, $external) {
            $locked = Release::whereKey($release->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== $from) {
                return false;
            }
            $current = $locked->external ?? [];
            $locked->update(['status' => $to, 'external' => array_merge($current, $external($current))]);

            return true;
        });
        if ($moved) {
            $release->refresh();
        }

        return $moved;
    }

    /** @return array<string, string> */
    private function inputs(Release $release): array
    {
        $game = $release->game;

        return [
            'game' => $game->slug,
            'commit_sha' => $release->commit_sha,
            'release_uuid' => $release->uuid,
            'build_number' => (string) $release->build_number,
            'marketing_version' => (string) $this->version($release),
            'bundle_id' => (string) $game->bundle_id,
            'profile_id' => (string) $game->appStoreId('profile_id'),
            'build_guide' => (string) $release->build_guide,
        ];
    }

    private function followRun(Release $release): ?int
    {
        $external = $release->external ?? [];
        $runId = $external['github_run_id'];
        if (! is_int($runId)) {
            throw new \LogicException('BUILDING-släpp med ogiltigt github_run_id.');
        }
        $repo = (string) config('releases.ios_workflow.repo');
        $limit = (int) config('releases.timeouts.github_run_minutes');
        $overdue = Carbon::parse($external['dispatched_at'])->lt(now()->subMinutes($limit));
        $poll = (int) config('releases.poll_seconds.github');
        $reason = "The GitHub run did not finish within {$limit} minutes as far as Computercat could read";
        try {
            $run = $this->github->workflowRun($repo, $runId);
        } catch (ExternalApiException $e) {
            return $this->tolerate($release, $e, $overdue, $reason, $poll);
        }

        if ($run['status'] !== 'completed') {
            if ($overdue) {
                // Körningen lever: fail() avbryter den och låter svepet försöka igen om det inte går (cancel_pending).
                throw new ReleaseFailed("The GitHub run did not finish within {$limit} minutes (status {$run['status']}); Computercat cancels it.");
            }

            return $poll;
        }

        if ($run['conclusion'] !== 'success') {
            try {
                $jobs = $this->github->workflowRunJobs($repo, $runId);
            } catch (ExternalApiException $e) {
                return $this->tolerate($release, $e, $overdue, $reason, $poll, runFinished: true);
            }
            $failed = collect($jobs)
                ->first(fn (array $job) => in_array($job['conclusion'], ['failure', 'timed_out', 'cancelled'], true));
            $where = $failed === null ? '' : " in job {$failed['name']}".($failed['failed_step'] === null ? '' : ", step \"{$failed['failed_step']}\"");

            throw new ReleaseFailed('The GitHub run ended with '.($run['conclusion'] ?? 'no conclusion')."{$where}.", runFinished: true);
        }

        if (! $this->moveIf($release, Release::BUILDING, Release::PROCESSING, fn () => [
            'run_completed_at' => now()->toIso8601String(), 'workflow_sha' => $run['head_sha'],
        ])) {
            return null;
        }

        return (int) config('releases.poll_seconds.apple');
    }

    private function followApple(Release $release): ?int
    {
        $external = $release->external ?? [];
        $game = $release->game;
        $appId = $game->appStoreId('app_id') ?? throw new ReleaseFailed('The game has no App Store app id; ask Erik to check the setup steps.', runFinished: true);
        $groupId = $game->appStoreId('beta_group_id') ?? throw new ReleaseFailed('The game has no TestFlight group; ask Erik to run the setup steps in Filament.', runFinished: true);

        $limit = (int) config('releases.timeouts.apple_processing_minutes');
        $overdue = Carbon::parse($external['run_completed_at'])->lt(now()->subMinutes($limit));
        $poll = (int) config('releases.poll_seconds.apple');
        $version = (string) $this->version($release);
        try {
            $build = $this->asc->findBuild($appId, $version, (int) $release->build_number);
        } catch (ExternalApiException $e) {
            return $this->tolerate($release, $e, $overdue, "Apple did not finish processing build {$release->build_number} (version {$version}) within {$limit} minutes as far as Computercat could read", $poll, runFinished: true);
        }
        if ($build === null || $build['state'] === 'PROCESSING') {
            if ($overdue) {
                throw new ReleaseFailed("Apple did not finish processing build {$release->build_number} (version {$version}) within {$limit} minutes.", runFinished: true);
            }

            return $poll;
        }

        if ($build['state'] !== 'VALID') {
            throw new ReleaseFailed("Apple marked build {$release->build_number} (version {$release->marketing_version}) as {$build['state']}.", runFinished: true);
        }

        // R7: gruppen har tillgång till alla byggen, men invarianten kontrolleras uttryckligen.
        // Ett tillfälligt fel här ger ett nytt försök: kontrollen före länkningen gör det upprepningsbart.
        try {
            if (! $this->asc->buildIsInGroup($groupId, $build['id'])) {
                $this->asc->addBuildToGroup($groupId, $build['id']);
            }
        } catch (ExternalApiException $e) {
            return $this->tolerate($release, $e, $overdue, "Build {$release->build_number} could not be added to the TestFlight group within {$limit} minutes", $poll, runFinished: true);
        }

        if (! $this->moveIf($release, Release::PROCESSING, Release::SUCCEEDED, fn () => [
            'apple_build_id' => $build['id'], 'succeeded_at' => now()->toIso8601String(),
        ])) {
            return null;
        }
        Log::notice('TestFlight-bygge klart', [
            'release' => $release->uuid, 'game' => $game->slug, 'commit' => $release->commit_sha,
            'version' => $release->marketing_version, 'build' => $release->build_number, 'workflow_sha' => $external['workflow_sha'] ?? null,
        ]);

        return null;
    }

    /** @return array{id: int, html_url: string}|null */
    private function findRun(Release $release): ?array
    {
        $since = Carbon::parse($release->external['dispatch_requested_at'] ?? now()->toIso8601String());

        return $this->github->findWorkflowRun(
            (string) config('releases.ios_workflow.repo'),
            (string) config('releases.ios_workflow.workflow'),
            $release->uuid,
            $since->copy()->subMinute(),
        );
    }

    /**
     * Avbryter släppets körning om den finns (känd eller hittad via run-name). Fel loggas; de får inte
     * dölja orsaken. @return bool true = avbruten eller inget att avbryta; false = försök igen senare
     */
    private function cancelRunOf(Release $release): bool
    {
        $external = $release->external ?? [];
        try {
            $runId = is_int($external['github_run_id'] ?? null) ? $external['github_run_id']
                : (isset($external['dispatch_requested_at']) ? $this->findRun($release)['id'] ?? null : null);
            if ($runId !== null) {
                $this->github->cancelWorkflowRun((string) config('releases.ios_workflow.repo'), $runId);
                Log::notice('GitHub-körning avbruten för fällt släpp', ['release' => $release->uuid, 'run' => $runId]);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Kunde inte avbryta GitHub-körningen', ['release' => $release->uuid, 'error' => get_class($e)]);

            return false;
        }
    }
}
