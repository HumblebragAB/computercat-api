<?php

namespace App\Jobs;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\SafeMessage;
use App\Provisioning\Saga;
use App\Provisioning\StepResult;
use App\Support\SlackAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Kör nästa ej klara steg i en run. Ett klart steg köar ett nytt jobb för
// nästa; ett väntande steg släpper jobbet (release) och körs igen.
//
// Köinställningarna är medvetna (Review Focus 1): workern körs med --tries=1,
// och varje release() räknas som ett försök. $tries = 0 tar bort taket,
// retryUntil() sätter gränsen i tid i stället. Med obegränsade försök får ett
// undantag aldrig nå kön; handle() fångar därför allt och markerar runnen.
class AdvanceProvisioningRun implements ShouldQueue
{
    // Ingen SerializesModels: jobbet bär bara run-id:t och läser runnen färskt.
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    // Under databasköns retry_after (90 s), annars körs jobbet två gånger.
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    // Ett undantag utanför stegets try (t.ex. databasen borta när steget
    // markeras) når kön. Med $tries = 0 körs jobbet då om tills retryUntil();
    // backoff hindrar att det sker i en tät loop.
    public int $backoff = 30;

    public function __construct(public int $runId) {}

    public function retryUntil(): \Illuminate\Support\Carbon
    {
        // Ett nytt jobb köas per steg, så fönstret gäller ett steg i taget.
        // Längsta väntan: Coolify-radering (10 min) eller README (3 min), plus marginal.
        return now()->addMinutes(max((int) config('provisioning.coolify_delete_timeout_minutes'), (int) config('provisioning.repo_ready_timeout_minutes')) + 10);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('provisioning-run-'.$this->runId))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(SlackAlert $slack): void
    {
        $run = ProvisioningRun::find($this->runId);
        if ($run === null || $run->status !== ProvisioningRun::RUNNING) {
            return;
        }

        $step = $run->steps()->where('status', '!=', ProvisioningStep::DONE)->first();
        if ($step === null) {
            $this->complete($run);

            return;
        }

        $step->increment('attempts');

        try {
            $result = Saga::handler($run, $step->step)->run($run, $step);
        } catch (Throwable $e) {
            $this->markFailed($run, $step, $e, $slack);

            return;
        }

        $step->update(['detail' => array_merge($step->detail ?? [], $result->detail)]);

        match ($result->outcome) {
            StepResult::DONE => $this->advance($step),
            StepResult::WAIT => $this->release($result->seconds),
            StepResult::RELEASE => $this->releaseSlug($run, $step, (string) $result->reason, $slack),
        };
    }

    // Timeout eller passerat retryUntil(): jobbet dör utan att handle() hann markera något.
    public function failed(?Throwable $e): void
    {
        $run = ProvisioningRun::find($this->runId);
        if ($run === null || $run->status !== ProvisioningRun::RUNNING) {
            return;
        }

        $step = $run->steps()->where('status', '!=', ProvisioningStep::DONE)->first();
        $step?->update(['status' => ProvisioningStep::FAILED]);
        $run->update([
            'status' => ProvisioningRun::FAILED,
            'last_error' => ($step?->step ?? 'saga').': the step did not finish in time (queue timeout or retry window).',
        ]);
        Log::error('Provisioneringsjobbet dog', ['run' => $run->uuid, 'exception' => $e]);
        app(SlackAlert::class)->send("provisioning-failed:{$run->uuid}:".($step?->step ?? 'saga'), "Provisioneringen av `{$run->slug}` dog i kön i steget ".($step?->step ?? '?')." (run {$run->uuid}).");
    }

    private function advance(ProvisioningStep $step): void
    {
        $step->update(['status' => ProvisioningStep::DONE]);
        self::dispatch($this->runId);
    }

    private function complete(ProvisioningRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run->update(['status' => ProvisioningRun::SUCCEEDED]);

            if ($run->kind === ProvisioningRun::KIND_ARCHIVE && $run->game_id !== null) {
                ProvisioningRun::provision()
                    ->where('game_id', $run->game_id)
                    ->where('status', ProvisioningRun::SUCCEEDED)
                    ->update(['status' => ProvisioningRun::ARCHIVED]);
            }
        });
    }

    // Inte fail(): namnet tillhör InteractsWithQueue och skulle markera jobbet som misslyckat i kön.
    private function markFailed(ProvisioningRun $run, ProvisioningStep $step, Throwable $e, SlackAlert $slack): void
    {
        $ref = (string) Str::uuid();
        // Bara undantag som lovar ett redigerat meddelande visas för medlemmarna.
        $message = $e instanceof SafeMessage ? $e->getMessage() : "Internal error (ref {$ref}).";
        Log::error('Provisioneringssteg misslyckades', ['ref' => $ref, 'run' => $run->uuid, 'step' => $step->step, 'exception' => $e]);

        $step->update(['status' => ProvisioningStep::FAILED, 'detail' => array_merge($step->detail ?? [], ['error_ref' => $ref])]);
        $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => "{$step->step}: {$message}"]);

        $slack->send(
            "provisioning-failed:{$run->uuid}:{$step->step}",
            "Provisioneringen av `{$run->slug}` stannade i steget {$step->step}: ".SlackAlert::escape($run->last_error)." (run {$run->uuid}, ref {$ref}). Fortsätt med retry_provisioning när orsaken är åtgärdad.",
        );
    }

    private function releaseSlug(ProvisioningRun $run, ProvisioningStep $step, string $reason, SlackAlert $slack): void
    {
        $step->update(['status' => ProvisioningStep::FAILED]);
        $run->update(['status' => ProvisioningRun::RELEASED, 'slug_lock' => null, 'last_error' => "{$step->step}: {$reason}"]);
        $slack->send("provisioning-released:{$run->uuid}", "Slugen `{$run->slug}` släpptes: ".SlackAlert::escape($reason)." (run {$run->uuid}).");
    }
}
