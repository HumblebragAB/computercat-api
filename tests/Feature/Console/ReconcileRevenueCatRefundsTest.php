<?php

namespace Tests\Feature\Console;

use App\Models\Game;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileRevenueCatRefundsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->game = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
    }

    private function purchase(string $tx, string $status, ?array $receipt): Purchase
    {
        return Purchase::create([
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'product_id' => 'tocco.pack.animals',
            'store' => 'apple',
            'transaction_id' => $tx,
            // Webhooken sparar hela händelsen, med radens transaction_id.
            'receipt_data' => $receipt === null ? null : json_encode($receipt + ['transaction_id' => $tx]),
            'status' => $status,
            'purchased_at' => now(),
        ]);
    }

    private function seedPurchases(): array
    {
        return [
            'refund' => $this->purchase('tx-refund', 'pending', ['type' => 'CANCELLATION', 'cancel_reason' => 'CUSTOMER_SUPPORT', 'expiration_at_ms' => null]),
            'unsub' => $this->purchase('tx-unsub', 'pending', ['type' => 'CANCELLATION', 'cancel_reason' => 'UNSUBSCRIBE', 'expiration_at_ms' => 1602022566000]),
            'already' => $this->purchase('tx-already', 'refunded', ['type' => 'CANCELLATION', 'cancel_reason' => 'CUSTOMER_SUPPORT']),
            'bought' => $this->purchase('tx-bought', 'verified', ['type' => 'NON_RENEWING_PURCHASE', 'expiration_at_ms' => null]),
            'suspect' => $this->purchase('tx-suspect', 'pending', ['type' => 'NON_RENEWING_PURCHASE', 'expiration_at_ms' => null]),
            'manual' => $this->purchase('tx-manual', 'pending', null),
            'garbage' => $this->purchase('tx-garbage', 'pending', null),
            'verified_manual' => $this->purchase('tx-verified-manual', 'verified', null),
        ];
    }

    public function test_default_is_dry_run_and_changes_nothing(): void
    {
        $p = $this->seedPurchases();

        $this->artisan('revenuecat:reconcile-refunds')
            ->expectsOutputToContain('Att markera som refunded: 1')
            ->expectsOutputToContain('tx-refund')
            ->expectsOutputToContain('Torrkörning')
            ->assertSuccessful();

        $this->assertSame('pending', $p['refund']->fresh()->status);
    }

    public function test_explicit_dry_run_changes_nothing(): void
    {
        $p = $this->seedPurchases();

        $this->artisan('revenuecat:reconcile-refunds', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('pending', $p['refund']->fresh()->status);
    }

    public function test_apply_marks_only_customer_support_cancellations(): void
    {
        $p = $this->seedPurchases();

        $this->artisan('revenuecat:reconcile-refunds', ['--apply' => true])
            ->expectsOutputToContain('Att markera som refunded: 1')
            ->expectsOutputToContain('Uppdaterade 1')
            ->assertSuccessful();

        $this->assertSame('refunded', $p['refund']->fresh()->status);
        $this->assertSame('pending', $p['unsub']->fresh()->status);
        $this->assertSame('verified', $p['bought']->fresh()->status);
        $this->assertSame('pending', $p['suspect']->fresh()->status);
        $this->assertSame('pending', $p['manual']->fresh()->status);
    }

    public function test_lists_webhook_one_time_purchases_stuck_in_pending_without_changing_them(): void
    {
        $p = $this->seedPurchases();

        $this->artisan('revenuecat:reconcile-refunds', ['--apply' => true])
            ->expectsOutputToContain('Misstänkta (ändras inte): 1')
            ->expectsOutputToContain('tx-suspect')
            ->assertSuccessful();

        $this->assertSame('pending', $p['suspect']->fresh()->status);
    }

    public function test_lists_verify_created_pending_rows_without_changing_them(): void
    {
        $p = $this->seedPurchases();

        $this->artisan('revenuecat:reconcile-refunds', ['--apply' => true])
            ->expectsOutputToContain('Pending utan webhook (räknas inte som ägda, ändras inte): 2')
            ->expectsOutputToContain('tx-manual')
            ->expectsOutputToContain('tx-garbage')
            ->assertSuccessful();

        $this->assertSame('pending', $p['manual']->fresh()->status);
        $this->assertSame('pending', $p['garbage']->fresh()->status);
    }

    public function test_apply_and_dry_run_together_is_rejected(): void
    {
        $p = $this->seedPurchases();

        $this->artisan('revenuecat:reconcile-refunds', ['--apply' => true, '--dry-run' => true])->assertFailed();

        $this->assertSame('pending', $p['refund']->fresh()->status);
    }

    public function test_non_json_receipt_data_is_skipped(): void
    {
        Purchase::create([
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'product_id' => 'p',
            'store' => 'web',
            'transaction_id' => 'tx-x',
            'receipt_data' => 'not json {',
            'status' => 'pending',
            'purchased_at' => now(),
        ]);

        $this->artisan('revenuecat:reconcile-refunds', ['--apply' => true])
            ->expectsOutputToContain('Att markera som refunded: 0')
            ->assertSuccessful();
    }
}
