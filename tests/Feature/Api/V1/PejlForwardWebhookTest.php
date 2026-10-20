<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendPejlForward;
use App\Models\Game;
use App\Models\PejlForward;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Pejl\ForwardRecorder;
use App\Support\SlackAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Webhooken registrerar köp och återbetalningar som ska vidarebefordras till
 * Pejl. Fältnamn enligt RevenueCat:
 * https://www.revenuecat.com/docs/integrations/webhooks/event-types-and-fields
 */
class PejlForwardWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'glosis-webhook-secret';

    private Game $glosis;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        $this->glosis = Game::where('slug', 'glosis')->firstOrFail();
        $this->glosis->update(['settings' => [
            'site_url' => 'https://glosis.se',
            'revenuecat' => ['webhook_secret' => Crypt::encryptString(self::SECRET)],
        ]]);

        Product::create([
            'game_id' => $this->glosis->id,
            'product_id' => 'glosis_guld_2026_27',
            'reference_name' => 'Guld',
            'product_type' => 'non_consumable',
            'grant_type' => 'custom',
            'price' => 99,
            'display_name' => 'Guldstjärnan 2026/27',
            'description' => 'Guld',
        ]);
    }

    private function postEvent(array $event): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/revenuecat/glosis', ['event' => $event, 'api_version' => '1.0'], ['Authorization' => 'Bearer '.self::SECRET]);
    }

    private function event(string $type, array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt-'.uniqid(),
            'type' => $type,
            'app_user_id' => '$RCAnonymousID:4c1a8f1e2b9d4e0f',
            'product_id' => 'glosis_guld_2026_27',
            'transaction_id' => '2000000123456789',
            'original_transaction_id' => '2000000123456789',
            'purchased_at_ms' => 1758000000000,
            'expiration_at_ms' => null,
            'event_timestamp_ms' => 1759000000000,
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            'country_code' => 'SE',
            'currency' => 'SEK',
            'price_in_purchased_currency' => 99.0,
            'price' => 9.12,
            'tax_percentage' => 0.2,
            'commission_percentage' => 0.15,
        ], $overrides);
    }

    private function sale(array $overrides = []): array
    {
        return $this->event('NON_RENEWING_PURCHASE', $overrides);
    }

    private function refund(array $overrides = []): array
    {
        return $this->event('CANCELLATION', array_merge(['cancel_reason' => 'CUSTOMER_SUPPORT', 'price_in_purchased_currency' => -99.0, 'price' => -9.12], $overrides));
    }

    private function markSaleSent(): PejlForward
    {
        $sale = PejlForward::where('kind', 'sale')->sole();
        $sale->update(['status' => 'sent', 'sent_at' => now(), 'fx_rate' => 1, 'fx_date' => '2025-09-16']);

        return $sale;
    }

    public function test_sek_sale_records_pending_forward_and_queues_job(): void
    {
        $this->postEvent($this->sale(['id' => 'evt-sale-1']))->assertOk();

        $row = PejlForward::sole();
        $this->assertSame($this->glosis->id, $row->game_id);
        $this->assertSame('evt-sale-1', $row->event_id);
        $this->assertSame('sale', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertSame('APP_STORE', $row->store);
        $this->assertSame('2000000123456789', $row->transaction_id);
        $this->assertSame('APP_STORE:2000000123456789', $row->external_reference);
        $this->assertSame('Guldstjärnan 2026/27 · App Store · SE', $row->description);
        $this->assertSame('99.0000', $row->amount);
        $this->assertSame('SEK', $row->currency);
        $this->assertSame('0.200000', $row->tax_percentage);
        $this->assertSame('0.150000', $row->commission_percentage);
        $this->assertNull($row->fx_rate);
        $this->assertSame(1758000000, $row->occurred_at->getTimestamp());

        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $row->id);
        Queue::assertPushed(SendPejlForward::class, 1);
    }

    public function test_sandbox_event_is_not_forwarded(): void
    {
        $this->postEvent($this->sale(['environment' => 'SANDBOX']))->assertOk();

        $this->assertSame(0, PejlForward::count());
        Queue::assertNothingPushed();
    }

    public function test_anonymous_glosis_buyer_is_forwarded(): void
    {
        $this->postEvent($this->sale(['app_user_id' => '$RCAnonymousID:x', 'type' => 'INITIAL_PURCHASE']))
            ->assertOk()
            ->assertJson(['message' => 'User not found, ignoring.']);

        $this->assertSame(1, PejlForward::count());
    }

    public function test_redelivered_event_gives_one_row_and_one_job(): void
    {
        $event = $this->sale();

        $this->postEvent($event)->assertOk();
        $this->postEvent($event)->assertOk();

        $this->assertSame(1, PejlForward::count());
        Queue::assertPushed(SendPejlForward::class, 1);
    }

    public function test_concurrent_redelivery_returns_existing_row_without_alarm(): void
    {
        $this->mock(SlackAlert::class)->shouldNotReceive('send');
        PejlForward::creating(function (PejlForward $forward) {
            if (DB::table('pejl_forwards')->where('external_reference', $forward->external_reference)->doesntExist()) {
                DB::table('pejl_forwards')->insert(['game_id' => $forward->game_id, 'event_id' => $forward->event_id, 'kind' => 'sale', 'external_reference' => $forward->external_reference, 'status' => 'sent']);
            }
        });

        $this->postEvent($this->sale())->assertOk();

        $this->assertSame('sent', PejlForward::sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_expiration_and_uncancellation_are_not_forwarded(): void
    {
        $this->postEvent($this->event('EXPIRATION'))->assertOk();
        $this->postEvent($this->event('UNCANCELLATION'))->assertOk();
        $this->postEvent($this->event('CANCELLATION', ['cancel_reason' => 'UNSUBSCRIBE', 'expiration_at_ms' => 1790000000000]))->assertOk();

        $this->assertSame(0, PejlForward::count());
    }

    public function test_refund_of_unknown_purchase_is_skipped(): void
    {
        $this->postEvent($this->refund())->assertOk();

        $row = PejlForward::sole();
        $this->assertSame('refund', $row->kind);
        $this->assertSame('skipped', $row->status);
        $this->assertSame('Köpet gjordes före vidarebefordringen', $row->last_error);
        Queue::assertNothingPushed();
    }

    public function test_refund_of_unsent_purchase_waits_without_job(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $this->postEvent($this->refund(['id' => 'evt-refund-1']))->assertOk();

        $row = PejlForward::where('kind', 'refund')->sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('APP_STORE:2000000123456789:refund:evt-refund-1', $row->external_reference);
        $this->assertSame('Återbetalning: Guldstjärnan 2026/27 · App Store · SE', $row->description);
        $this->assertSame(1759000000, $row->occurred_at->getTimestamp());
        Queue::assertPushed(SendPejlForward::class, 1);
    }

    public function test_refund_of_sent_purchase_copies_purchase_rate_and_percentages(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $sale = $this->markSaleSent();

        $this->postEvent($this->refund(['price_in_purchased_currency' => -49.5, 'tax_percentage' => null, 'commission_percentage' => null]))->assertOk();

        $row = PejlForward::where('kind', 'refund')->sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('49.5000', $row->amount);
        $this->assertSame('SEK', $row->currency);
        $this->assertSame($sale->tax_percentage, $row->tax_percentage);
        $this->assertSame($sale->commission_percentage, $row->commission_percentage);
        $this->assertSame('1.000000', $row->fx_rate);
        $this->assertSame('2025-09-16', $row->fx_date->toDateString());
        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $row->id);
    }

    public function test_refund_without_price_takes_purchase_amount(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $this->markSaleSent();

        $refund = $this->refund();
        unset($refund['price_in_purchased_currency'], $refund['price']);
        $this->postEvent($refund)->assertOk();

        $this->assertSame('99.0000', PejlForward::where('kind', 'refund')->sole()->amount);
    }

    public function test_refund_larger_than_purchase_fails(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $this->markSaleSent();

        $this->postEvent($this->refund(['price_in_purchased_currency' => -150.0]))->assertOk();

        $row = PejlForward::where('kind', 'refund')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->last_error);
        Queue::assertPushed(SendPejlForward::class, 1);
    }

    public function test_missing_commission_percentage_fails(): void
    {
        $this->mock(SlackAlert::class)->shouldReceive('send')->once()->withArgs(fn ($key, $text) => str_starts_with($key, 'pejl-forward-') && str_contains($text, 'commission_percentage'))->andReturn(SlackAlert::SENT);
        $sale = $this->sale();
        unset($sale['commission_percentage']);

        $this->postEvent($sale)->assertOk();

        $row = PejlForward::sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('commission_percentage', $row->last_error);
        Queue::assertNothingPushed();
    }

    public function test_forward_failure_never_breaks_webhook(): void
    {
        $user = User::factory()->create();
        $this->mock(ForwardRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('trasig valuta'));
        $this->mock(SlackAlert::class)->shouldReceive('send')->once()->withArgs(fn ($key) => $key === 'pejl-forward-record-evt-boom')->andReturn(SlackAlert::SENT);

        $this->postEvent($this->sale(['id' => 'evt-boom', 'app_user_id' => (string) $user->id]))
            ->assertOk()
            ->assertJson(['message' => 'OK']);

        $purchase = Purchase::sole();
        $this->assertSame($user->id, $purchase->user_id);
        $this->assertSame('verified', $purchase->status);
        $this->assertSame(0, PejlForward::count());
    }

    public function test_jpy_sale_takes_usd_price(): void
    {
        $this->postEvent($this->sale(['currency' => 'JPY', 'price_in_purchased_currency' => 1500, 'price' => 10.25]))->assertOk();

        $row = PejlForward::sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('10.2500', $row->amount);
        $this->assertSame('USD', $row->currency);
    }

    public function test_non_sek_sale_without_usd_price_fails(): void
    {
        $sale = $this->sale(['currency' => 'JPY', 'price_in_purchased_currency' => 1500]);
        unset($sale['price']);

        $this->postEvent($sale)->assertOk();

        $this->assertSame('failed', PejlForward::sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_missing_price_fails_instead_of_silent_null(): void
    {
        $sale = $this->sale();
        unset($sale['price_in_purchased_currency']);

        $this->postEvent($sale)->assertOk();

        $row = PejlForward::sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('price_in_purchased_currency', $row->last_error);
        Queue::assertNothingPushed();
    }

    public function test_free_trial_is_not_forwarded(): void
    {
        $this->postEvent($this->event('INITIAL_PURCHASE', ['price_in_purchased_currency' => 0, 'price' => 0, 'expiration_at_ms' => 1790000000000]))->assertOk();

        $this->assertSame(0, PejlForward::count());
    }

    public function test_zero_tax_percentage_is_valid(): void
    {
        $this->postEvent($this->sale(['tax_percentage' => 0]))->assertOk();

        $row = PejlForward::sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('0.000000', $row->tax_percentage);
    }

    public function test_refund_reversed_after_sent_refund_records_reversal(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $sale = $this->markSaleSent();
        $this->postEvent($this->refund(['price_in_purchased_currency' => -49.5]))->assertOk();
        PejlForward::where('kind', 'refund')->update(['status' => 'sent']);

        $this->postEvent($this->event('REFUND_REVERSED', ['id' => 'evt-rev-1']))->assertOk();

        $row = PejlForward::where('kind', 'refund_reversed')->sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('APP_STORE:2000000123456789:refund-reversed:evt-rev-1', $row->external_reference);
        $this->assertSame('49.5000', $row->amount);
        $this->assertSame('SEK', $row->currency);
        $this->assertSame($sale->tax_percentage, $row->tax_percentage);
        $this->assertSame('1.000000', $row->fx_rate);
        $this->assertSame(1759000000, $row->occurred_at->getTimestamp());
        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $row->id);
    }

    public function test_refund_reversed_waits_when_refund_failed(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $this->markSaleSent();
        $this->postEvent($this->refund())->assertOk();
        PejlForward::where('kind', 'refund')->update(['status' => 'failed']);
        Queue::fake();

        $this->postEvent($this->event('REFUND_REVERSED'))->assertOk();

        $this->assertSame('pending', PejlForward::where('kind', 'refund_reversed')->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_refund_reversed_without_purchase_is_skipped(): void
    {
        $this->postEvent($this->event('REFUND_REVERSED'))->assertOk();

        $this->assertSame('skipped', PejlForward::sole()->status);
    }

    public function test_refund_reversed_refund_cycle_gives_three_rows(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $this->markSaleSent();

        $this->postEvent($this->refund(['id' => 'evt-r1']))->assertOk();
        PejlForward::where('kind', 'refund')->update(['status' => 'sent']);
        $this->postEvent($this->event('REFUND_REVERSED', ['id' => 'evt-rr1']))->assertOk();
        PejlForward::where('kind', 'refund_reversed')->update(['status' => 'sent']);
        $this->postEvent($this->refund(['id' => 'evt-r2']))->assertOk();

        $this->assertEqualsCanonicalizing([
            'APP_STORE:2000000123456789:refund:evt-r1',
            'APP_STORE:2000000123456789:refund-reversed:evt-rr1',
            'APP_STORE:2000000123456789:refund:evt-r2',
        ], PejlForward::where('kind', '!=', 'sale')->pluck('external_reference')->all());
        $this->assertSame('pending', PejlForward::where('external_reference', 'APP_STORE:2000000123456789:refund:evt-r2')->sole()->status);
    }

    public function test_second_reversal_needs_a_second_refund(): void
    {
        $this->postEvent($this->sale())->assertOk();
        $this->markSaleSent();
        $this->postEvent($this->refund())->assertOk();
        PejlForward::where('kind', 'refund')->update(['status' => 'sent']);
        $this->postEvent($this->event('REFUND_REVERSED'))->assertOk();
        Queue::fake();

        $this->postEvent($this->event('REFUND_REVERSED'))->assertOk();

        $this->assertSame(['pending', 'pending'], PejlForward::where('kind', 'refund_reversed')->orderBy('id')->pluck('status')->all());
        $this->assertNull(PejlForward::where('kind', 'refund_reversed')->orderByDesc('id')->first()->amount);
        Queue::assertNothingPushed();
    }

    public function test_stripe_is_not_forwarded(): void
    {
        $this->postEvent($this->sale(['store' => 'STRIPE']))->assertOk();

        $this->assertSame(0, PejlForward::count());
    }

    public function test_play_store_description_and_reference(): void
    {
        Product::query()->delete();

        $this->postEvent($this->sale(['store' => 'PLAY_STORE', 'transaction_id' => 'GPA.1234-5678', 'country_code' => 'NO']))->assertOk();

        $row = PejlForward::sole();
        $this->assertSame('PLAY_STORE:GPA.1234-5678', $row->external_reference);
        $this->assertSame('glosis_guld_2026_27 · Google Play · NO', $row->description);
    }
}
