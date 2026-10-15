<?php

namespace App\Jobs;

use App\Models\Release;
use App\Releases\TestFlight\ReleaseFailed;
use App\Releases\TestFlight\TestFlightBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

// Driver ett TestFlight-släpp ett steg i taget och släpper sig självt (release) tills nästa kontroll,
// som AdvanceProvisioningRun. Workern körs med --tries=1: $tries = 0 tar bort taket och retryUntil()
// sätter gränsen i tid (båda tidsgränserna plus marginal, R11). failOnTimeout = false: ett långsamt
// anrop ger ett nytt försök, inte ett fällt släpp; markörerna i external hindrar en andra dispatch
// (R15, R16). TestFlightBuilder fångar allt och fäller släppet själv; failed() täcker det som dör i
// kön och avbryter då släppets körning. Unikt per släpp, så att svepet aldrig ger två jobb.
class AdvanceRelease implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    // Under databasköns retry_after (90 s). En körning gör högst fem anrop (token, profil, tagg x2,
    // dispatch) à högst 20 s; går den över gäller failOnTimeout = false och jobbet körs om.
    public int $timeout = 85;

    public bool $failOnTimeout = false;

    public int $backoff = 30;

    public int $uniqueFor = 4 * 3600;

    public function __construct(public int $releaseId) {}

    public function uniqueId(): string
    {
        return 'release-'.$this->releaseId;
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes((int) config('releases.timeouts.github_run_minutes') + (int) config('releases.timeouts.apple_processing_minutes') + 30);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('release-'.$this->releaseId))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(TestFlightBuilder $builder): void
    {
        $release = Release::with('game')->find($this->releaseId);
        if ($release === null || ! $builder->handles($release)) {
            return;
        }

        $next = $builder->advance($release);
        if ($next !== null) {
            $this->release($next);
        }
    }

    public function failed(?Throwable $e): void
    {
        $release = Release::with('game')->find($this->releaseId);
        if ($release === null || ! app(TestFlightBuilder::class)->handles($release)) {
            return;
        }

        app(TestFlightBuilder::class)->fail($release, new ReleaseFailed('The release job did not finish in time (queue timeout or retry window).'));
    }
}
