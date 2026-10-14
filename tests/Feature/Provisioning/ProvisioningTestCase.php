<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

abstract class ProvisioningTestCase extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesProvisioningRuns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        // Provisioneringen bakar in API:ts värd i Coolify-appen; lokala värdar nekas
        // (BuildGuide::coolifyBuildCommand), så testerna får prod-värden oavsett .env.
        config(['app.url' => 'https://api.computercat.co']);
        $this->startHttpTransactionGuard();
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }
}
