<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\CoolifyGuardException;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

// Raderar bara det sparade uuid:t (spärren i klienten kontrollerar run och
// Demo-projekt). Radering är asynkron i Coolify; steget väntar tills appen
// försvunnit ur projektet.
final class CoolifyDeleteStep implements Step
{
    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $uuid = $run->coolify_app_uuid;
        if ($uuid === null) {
            return StepResult::done(['skipped' => 'no Coolify application recorded']);
        }

        $detail = $step->detail ?? [];
        if (! ($detail['delete_requested'] ?? false)) {
            // Redan borta ur Demo-projektet (raderad för hand): inget DELETE, och
            // spärren i klienten skulle annars vägra uuid:t för alltid.
            if (! collect($this->coolify->demoApplications())->contains(fn (array $app) => $app['uuid'] === $uuid)) {
                return StepResult::done(['skipped' => 'already gone']);
            }

            // Markören sparas före DELETE: dör jobbet mellan anropet och svaret
            // pollar omkörningen listningen i stället för att begära radering igen
            // (spärren skulle då vägra ett uuid som redan är borta).
            $marker = ['delete_requested' => true, 'delete_requested_at' => now()->toIso8601String()];
            $step->update(['detail' => [...$detail, ...$marker]]);

            try {
                $this->coolify->deleteApplication($uuid);
            } catch (CoolifyGuardException|ExternalApiException $e) {
                // Spärren vägrade (inget skickat) eller Coolify svarade med en
                // felstatus: raderingen begärdes bevisligen inte, så markören tas
                // bort och retry_provisioning begär den igen. Vid status 0
                // (anslutning/timeout) är utfallet okänt och markören står kvar.
                if ($e instanceof CoolifyGuardException || $e->status !== 0) {
                    $step->update(['detail' => $detail]);
                }

                throw $e;
            }

            return StepResult::wait(30, $marker);
        }

        foreach ($this->coolify->demoApplications() as $app) {
            if ($app['uuid'] === $uuid) {
                if (Carbon::parse($detail['delete_requested_at'])->addMinutes((int) config('provisioning.coolify_delete_timeout_minutes'))->isPast()) {
                    // Markören tas bort så att retry_provisioning begär raderingen
                    // igen i stället för att genast falla på samma passerade deadline.
                    $step->update(['detail' => Arr::except($detail, ['delete_requested', 'delete_requested_at'])]);

                    throw new StepFailed("Coolify still lists application {$uuid} ".config('provisioning.coolify_delete_timeout_minutes').' minutes after the delete request.');
                }

                return StepResult::wait(30);
            }
        }

        return StepResult::done();
    }
}
