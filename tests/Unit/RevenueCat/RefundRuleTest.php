<?php

namespace Tests\Unit\RevenueCat;

use App\Services\RevenueCat\RefundRule;
use PHPUnit\Framework\TestCase;

class RefundRuleTest extends TestCase
{
    public function test_customer_support_cancellation_is_revocation(): void
    {
        $this->assertTrue(RefundRule::isRevocation('CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT', 'expiration_at_ms' => 1]));
    }

    public function test_unsubscribe_with_expiration_is_not_revocation(): void
    {
        $this->assertFalse(RefundRule::isRevocation('CANCELLATION', ['cancel_reason' => 'UNSUBSCRIBE', 'expiration_at_ms' => 1]));
    }

    public function test_one_time_cancellation_without_expiration_is_revocation(): void
    {
        $this->assertTrue(RefundRule::isRevocation('CANCELLATION', ['cancel_reason' => 'UNSUBSCRIBE']));
    }

    public function test_expiration_is_not_revocation(): void
    {
        $this->assertFalse(RefundRule::isRevocation('EXPIRATION', ['cancel_reason' => 'CUSTOMER_SUPPORT']));
    }
}
