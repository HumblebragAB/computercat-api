<?php

namespace Tests\Support;

use App\Models\ProvisioningRun;
use Illuminate\Support\Str;

trait CreatesProvisioningRuns
{
    protected function provisioningRun(array $attrs = []): ProvisioningRun
    {
        $slug = $attrs['slug'] ?? 's'.Str::lower(Str::random(8));
        $status = $attrs['status'] ?? ProvisioningRun::RUNNING;
        $kind = $attrs['kind'] ?? ProvisioningRun::KIND_PROVISION;

        return ProvisioningRun::create(array_merge([
            'slug' => $slug,
            // Bara provisioneringsruns som inte är released håller slugen.
            'slug_lock' => $kind === ProvisioningRun::KIND_PROVISION && $status !== ProvisioningRun::RELEASED ? $slug : null,
            'name' => ucfirst($slug),
            'user_id' => null,
            'game_id' => null,
            'kind' => $kind,
            'status' => $status,
            'idea' => 'En idé',
        ], $attrs));
    }
}
