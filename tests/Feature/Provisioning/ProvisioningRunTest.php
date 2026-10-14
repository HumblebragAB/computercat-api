<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\ProvisioningFakes;

class ProvisioningRunTest extends ProvisioningTestCase
{
    public function test_uuid_is_generated(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $run->uuid);
    }

    public function test_slug_lock_is_unique_until_released(): void
    {
        $first = $this->provisioningRun(['slug' => 'kattsvep']);

        try {
            $this->provisioningRun(['slug' => 'kattsvep']);
            $this->fail('Två runs fick hålla samma slug.');
        } catch (UniqueConstraintViolationException) {
            // väntat
        }

        $first->update(['status' => ProvisioningRun::RELEASED, 'slug_lock' => null]);
        $second = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->assertSame('kattsvep', $second->slug_lock);
    }

    public function test_last_error_is_scrubbed_and_limited(): void
    {
        $run = $this->provisioningRun();
        $run->update(['last_error' => 'x '.ProvisioningFakes::COOLIFY_TOKEN.' '.str_repeat('a', 6000)]);

        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $run->fresh()->last_error);
        $this->assertLessThanOrEqual(4096, strlen($run->fresh()->last_error));
    }

    public function test_cap_scope_counts_running_succeeded_failed_provision_runs_only(): void
    {
        foreach ([ProvisioningRun::RUNNING, ProvisioningRun::SUCCEEDED, ProvisioningRun::FAILED, ProvisioningRun::RELEASED, ProvisioningRun::ARCHIVED] as $status) {
            $this->provisioningRun(['status' => $status]);
        }
        $this->provisioningRun(['kind' => ProvisioningRun::KIND_ARCHIVE, 'status' => ProvisioningRun::RUNNING]);

        $this->assertSame(3, ProvisioningRun::countingTowardsCap()->count());
    }

    public function test_steps_are_unique_per_run_and_ordered(): void
    {
        $run = $this->provisioningRun();
        $run->steps()->create(['step' => 'repo', 'status' => ProvisioningStep::PENDING, 'attempts' => 0]);
        $run->steps()->create(['step' => 'game', 'status' => ProvisioningStep::PENDING, 'attempts' => 0]);

        $this->assertSame(['repo', 'game'], $run->steps()->pluck('step')->all());

        $this->expectException(UniqueConstraintViolationException::class);
        $run->steps()->create(['step' => 'repo', 'status' => ProvisioningStep::PENDING, 'attempts' => 0]);
    }
}
