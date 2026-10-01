<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fältnamn och eventtyper följer RevenueCats dokumentation:
 * https://www.revenuecat.com/docs/integrations/webhooks/event-types-and-fields
 * https://www.revenuecat.com/docs/integrations/webhooks/sample-events
 */
class RevenueCatWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    private User $user;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->game = Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
            'settings' => [
                'revenuecat' => ['webhook_secret' => Crypt::encryptString(self::SECRET)],
            ],
        ]);

        Product::create([
            'game_id' => $this->game->id,
            'product_id' => 'tocco.pack.animals',
            'reference_name' => 'Animals',
            'product_type' => 'non_consumable',
            'grant_type' => 'pack',
            'grant_id' => 'animals',
            'price' => 29,
            'display_name' => 'Animals',
            'description' => 'Animals pack',
        ]);
    }

    private function postEvent(array $event, ?Game $game = null): \Illuminate\Testing\TestResponse
    {
        $game ??= $this->game;

        return $this->postJson(
            "/api/v1/webhooks/revenuecat/{$game->slug}",
            ['event' => $event, 'api_version' => '1.0'],
            ['Authorization' => 'Bearer '.self::SECRET],
        );
    }

    /** Engångsköp enligt RevenueCats NON_RENEWING_PURCHASE-exempel. */
    private function nonRenewingEvent(array $overrides = []): array
    {
        return array_merge([
            'type' => 'NON_RENEWING_PURCHASE',
            'app_user_id' => (string) $this->user->id,
            'product_id' => 'tocco.pack.animals',
            'transaction_id' => '123456789012345',
            'original_transaction_id' => '123456789012345',
            'purchased_at_ms' => 1658726519000,
            'expiration_at_ms' => null,
            'store' => 'APP_STORE',
            'price' => 2.99,
        ], $overrides);
    }

    private function cancellationEvent(string $reason, array $overrides = []): array
    {
        return array_merge($this->nonRenewingEvent($overrides), [
            'type' => 'CANCELLATION',
            'cancel_reason' => $reason,
            'price' => -2.99,
        ]);
    }

    private function ownedPacks(?User $user = null): array
    {
        Sanctum::actingAs($user ?? $this->user);

        return $this->getJson('/api/v1/games/tocco/ownership')
            ->assertOk()
            ->json('data.owned_packs');
    }

    // -------------------------------------------------------
    // Köp och återbetalning
    // -------------------------------------------------------

    public function test_non_renewing_purchase_grants_ownership(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();

        $this->assertDatabaseHas('purchases', [
            'transaction_id' => '123456789012345',
            'user_id' => $this->user->id,
            'status' => 'verified',
        ]);
        $this->assertSame(['animals'], $this->ownedPacks());
    }

    public function test_cancellation_with_customer_support_marks_purchase_refunded(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $this->postEvent($this->cancellationEvent('CUSTOMER_SUPPORT'))->assertOk();

        $this->assertDatabaseHas('purchases', [
            'transaction_id' => '123456789012345',
            'status' => 'refunded',
        ]);
        $this->assertSame([], $this->ownedPacks());
    }

    public function test_cancellation_of_non_renewing_purchase_with_other_reason_revokes(): void
    {
        // Ett engångsköp har ingen period kvar att löpa ut; en CANCELLATION
        // betyder att köpet är makulerat oavsett vilket skäl butiken anger.
        foreach (['UNKNOWN', 'DEVELOPER_INITIATED'] as $i => $reason) {
            $tx = "tx-{$i}";
            $this->postEvent($this->nonRenewingEvent(['transaction_id' => $tx, 'original_transaction_id' => $tx]))->assertOk();
            $this->postEvent($this->cancellationEvent($reason, ['transaction_id' => $tx, 'original_transaction_id' => $tx]))->assertOk();

            $this->assertDatabaseHas('purchases', ['transaction_id' => $tx, 'status' => 'refunded']);
        }
    }

    public function test_subscription_cancellation_without_refund_keeps_grace_status(): void
    {
        $sub = [
            'type' => 'INITIAL_PURCHASE',
            'transaction_id' => 'sub-1',
            'original_transaction_id' => 'sub-1',
            'expiration_at_ms' => 1602022566000,
        ];
        $this->postEvent($this->nonRenewingEvent($sub))->assertOk();
        $this->postEvent($this->cancellationEvent('UNSUBSCRIBE', $sub))->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => 'sub-1', 'status' => 'pending']);
    }

    public function test_subscription_cancellation_with_customer_support_marks_refunded(): void
    {
        $sub = [
            'type' => 'INITIAL_PURCHASE',
            'transaction_id' => 'sub-2',
            'original_transaction_id' => 'sub-2',
            'expiration_at_ms' => 1602022566000,
        ];
        $this->postEvent($this->nonRenewingEvent($sub))->assertOk();
        $this->postEvent($this->cancellationEvent('CUSTOMER_SUPPORT', $sub))->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => 'sub-2', 'status' => 'refunded']);
    }

    public function test_refund_applies_even_when_app_user_id_is_unknown(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $this->postEvent($this->cancellationEvent('CUSTOMER_SUPPORT', ['app_user_id' => '$RCAnonymousID:abc']))->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'status' => 'refunded']);
    }

    public function test_refund_does_not_touch_other_games_purchases(): void
    {
        $other = Game::create([
            'slug' => 'other',
            'name' => 'Other',
            'is_active' => true,
            'settings' => ['revenuecat' => ['webhook_secret' => Crypt::encryptString(self::SECRET)]],
        ]);
        $this->postEvent($this->nonRenewingEvent())->assertOk();

        $this->postEvent($this->cancellationEvent('CUSTOMER_SUPPORT'), $other)->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'status' => 'verified']);
    }

    public function test_redelivered_purchase_event_does_not_undo_refund(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $this->postEvent($this->cancellationEvent('CUSTOMER_SUPPORT'))->assertOk();
        $this->postEvent($this->nonRenewingEvent())->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'status' => 'refunded']);
    }

    public function test_refund_reversed_restores_verified(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $this->postEvent($this->cancellationEvent('CUSTOMER_SUPPORT'))->assertOk();
        $this->postEvent($this->nonRenewingEvent(['type' => 'REFUND_REVERSED']))->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'status' => 'verified']);
        $this->assertSame(['animals'], $this->ownedPacks());
    }

    public function test_undocumented_refund_event_type_changes_nothing(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $this->postEvent($this->nonRenewingEvent(['type' => 'REFUND']))->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'status' => 'verified']);
    }

    // -------------------------------------------------------
    // Ägarskap
    // -------------------------------------------------------

    public function test_ownership_excludes_refunded_purchases(): void
    {
        Purchase::create([
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'product_id' => 'tocco.pack.animals',
            'store' => 'apple',
            'transaction_id' => 'refunded-tx',
            'status' => 'refunded',
            'purchased_at' => now(),
        ]);

        $this->assertSame([], $this->ownedPacks());
    }

    private function storedPurchase(string $tx, string $status, ?string $receipt): Purchase
    {
        return Purchase::create([
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'product_id' => 'tocco.pack.animals',
            'store' => 'apple',
            'transaction_id' => $tx,
            'receipt_data' => $receipt,
            'status' => $status,
            'purchased_at' => now(),
        ]);
    }

    public function test_ownership_ignores_pending_rows_not_from_webhook(): void
    {
        // Rader som skapats av det borttagna /purchases/verify: klientens
        // egna uppgifter, aldrig verifierade.
        $this->storedPurchase('verify-empty', 'pending', '');
        $this->storedPurchase('verify-null', 'pending', null);
        $this->storedPurchase('verify-junk', 'pending', 'MIIT...base64receipt');

        $this->assertSame([], $this->ownedPacks());
    }

    public function test_ownership_ignores_pending_row_with_forged_event_for_other_transaction(): void
    {
        $this->storedPurchase('verify-forged', 'pending', json_encode([
            'type' => 'NON_RENEWING_PURCHASE',
            'transaction_id' => 'something-else',
        ]));

        $this->assertSame([], $this->ownedPacks());
    }

    public function test_ownership_counts_pending_subscription_from_webhook(): void
    {
        $sub = [
            'type' => 'INITIAL_PURCHASE',
            'transaction_id' => 'sub-3',
            'original_transaction_id' => 'sub-3',
            'expiration_at_ms' => 1602022566000,
        ];
        $this->postEvent($this->nonRenewingEvent($sub))->assertOk();
        $this->postEvent($this->cancellationEvent('UNSUBSCRIBE', $sub))->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => 'sub-3', 'status' => 'pending']);
        $this->assertSame(['animals'], $this->ownedPacks());
    }

    public function test_ownership_ignores_failed_purchases(): void
    {
        $this->storedPurchase('failed-tx', 'failed', json_encode([
            'type' => 'NON_RENEWING_PURCHASE',
            'transaction_id' => 'failed-tx',
        ]));

        $this->assertSame([], $this->ownedPacks());
    }

    public function test_purchase_verify_endpoint_is_gone(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/purchases/verify', [
            'game_id' => $this->game->id,
            'product_id' => 'tocco.pack.animals',
            'store' => 'apple',
            'transaction_id' => 'free-unlock',
        ])->assertNotFound();

        $this->assertDatabaseMissing('purchases', ['transaction_id' => 'free-unlock']);
        $this->assertSame([], $this->ownedPacks());
    }

    // -------------------------------------------------------
    // TRANSFER
    // -------------------------------------------------------

    public function test_transfer_moves_purchases_to_destination_user(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $to = User::factory()->create();

        $this->postEvent([
            'type' => 'TRANSFER',
            'store' => 'APP_STORE',
            'transferred_from' => [(string) $this->user->id],
            'transferred_to' => [(string) $to->id],
        ])->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'user_id' => $to->id]);
        $this->assertSame(['animals'], $this->ownedPacks($to));
        $this->assertSame([], $this->ownedPacks($this->user));
    }

    public function test_transfer_ignores_unknown_and_anonymous_ids(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();

        $this->postEvent([
            'type' => 'TRANSFER',
            'transferred_from' => [(string) $this->user->id],
            'transferred_to' => ['$RCAnonymousID:4BEDB450', '999999'],
        ])->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'user_id' => $this->user->id]);
    }

    public function test_transfer_with_unknown_source_moves_nothing(): void
    {
        $this->postEvent($this->nonRenewingEvent())->assertOk();
        $to = User::factory()->create();

        $this->postEvent([
            'type' => 'TRANSFER',
            'transferred_from' => ['00005A1C-6091-4F81-BE77-F0A83A271AB6', '999999'],
            'transferred_to' => [(string) $to->id],
        ])->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => '123456789012345', 'user_id' => $this->user->id]);
    }

    public function test_transfer_only_moves_this_games_purchases(): void
    {
        $other = Game::create(['slug' => 'other', 'name' => 'Other', 'is_active' => true]);
        Purchase::create([
            'user_id' => $this->user->id,
            'game_id' => $other->id,
            'product_id' => 'other.pack',
            'store' => 'apple',
            'transaction_id' => 'other-tx',
            'status' => 'verified',
            'purchased_at' => now(),
        ]);
        $to = User::factory()->create();

        $this->postEvent([
            'type' => 'TRANSFER',
            'transferred_from' => [(string) $this->user->id],
            'transferred_to' => [(string) $to->id],
        ])->assertOk();

        $this->assertDatabaseHas('purchases', ['transaction_id' => 'other-tx', 'user_id' => $this->user->id]);
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->postJson('/api/v1/webhooks/revenuecat/tocco', ['event' => $this->cancellationEvent('CUSTOMER_SUPPORT')], [
            'Authorization' => 'Bearer wrong',
        ])->assertStatus(401);
    }
}
