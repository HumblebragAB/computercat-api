<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use App\Models\RevokedStoreTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Glosis har inga konton, så RevenueCats app_user_id är alltid ett anonymt
 * id. Återbetalningar måste ändå spärra fotoskanningen: de sparas per
 * original_transaction_id (RevenueCats fält, se
 * https://www.revenuecat.com/docs/integrations/webhooks/event-types-and-fields).
 */
class GlosisRevocationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'glosis-webhook-secret';

    private Game $glosis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->glosis = Game::where('slug', 'glosis')->firstOrFail();
        $this->glosis->update(['settings' => [
            'site_url' => 'https://glosis.se',
            'revenuecat' => ['webhook_secret' => Crypt::encryptString(self::SECRET)],
        ]]);
    }

    private function postEvent(array $event, string $slug = 'glosis'): TestResponse
    {
        return $this->postJson("/api/v1/webhooks/revenuecat/{$slug}", ['event' => $event, 'api_version' => '1.0'], ['Authorization' => 'Bearer '.self::SECRET]);
    }

    private function event(string $type, array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt-'.uniqid(),
            'type' => $type,
            'app_user_id' => '$RCAnonymousID:4c1a8f1e2b9d4e0f',
            'product_id' => 'glosis_guld_2026_27',
            'transaction_id' => '2000000123456789',
            'original_transaction_id' => '2000000111111111',
            'purchased_at_ms' => 1758000000000,
            'expiration_at_ms' => null,
            'event_timestamp_ms' => 1759000000000,
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
        ], $overrides);
    }

    public function test_refund_of_anonymous_glosis_purchase_is_denylisted(): void
    {
        $this->postEvent($this->event('CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT']))->assertOk();

        $row = RevokedStoreTransaction::sole();
        $this->assertSame($this->glosis->id, $row->game_id);
        $this->assertSame('apple', $row->store);
        $this->assertSame('2000000111111111', $row->original_transaction_id);
        $this->assertSame('2000000123456789', $row->transaction_id);
        $this->assertSame('CUSTOMER_SUPPORT', $row->reason);
        $this->assertSame(1759000000, $row->revoked_at->getTimestamp());
    }

    public function test_cancellation_of_one_time_purchase_with_other_reason_is_denylisted(): void
    {
        $this->postEvent($this->event('CANCELLATION', ['cancel_reason' => 'UNKNOWN', 'store' => 'PLAY_STORE', 'original_transaction_id' => 'GPA.1234-5678']))->assertOk();

        $row = RevokedStoreTransaction::sole();
        $this->assertSame('google', $row->store);
        $this->assertSame('GPA.1234-5678', $row->original_transaction_id);
        $this->assertSame('UNKNOWN', $row->reason);
    }

    public function test_subscription_unsubscribe_is_not_denylisted(): void
    {
        $this->postEvent($this->event('CANCELLATION', ['cancel_reason' => 'UNSUBSCRIBE', 'expiration_at_ms' => 1790000000000]))->assertOk();

        $this->assertSame(0, RevokedStoreTransaction::count());
    }

    public function test_redelivered_refund_is_idempotent(): void
    {
        $event = $this->event('CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT']);

        $this->postEvent($event)->assertOk();
        $this->postEvent($event)->assertOk();

        $this->assertSame(1, RevokedStoreTransaction::count());
    }

    public function test_refund_reversed_removes_denylist_entry(): void
    {
        $this->postEvent($this->event('CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT']))->assertOk();
        $this->postEvent($this->event('REFUND_REVERSED'))->assertOk();

        $this->assertSame(0, RevokedStoreTransaction::count());
    }

    public function test_missing_original_transaction_id_falls_back_to_transaction_id(): void
    {
        $event = $this->event('CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT']);
        unset($event['original_transaction_id']);

        $this->postEvent($event)->assertOk();

        $this->assertSame('2000000123456789', RevokedStoreTransaction::sole()->original_transaction_id);
    }

    public function test_other_games_do_not_write_the_denylist(): void
    {
        Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
            'settings' => ['revenuecat' => ['webhook_secret' => Crypt::encryptString(self::SECRET)]],
        ]);

        $this->postEvent($this->event('CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT']), 'tocco')->assertOk();

        $this->assertSame(0, RevokedStoreTransaction::count());
    }
}
