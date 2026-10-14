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
        $this->startHttpTransactionGuard();
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }
}
