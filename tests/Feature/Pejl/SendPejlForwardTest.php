<?php

namespace Tests\Feature\Pejl;

use App\Jobs\SendPejlForward;
use App\Models\Game;
use App\Models\PejlForward;
use App\Support\SlackAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Jobbet som postar en vidarebefordran till Pejls POST /api/revenue-items.
 * Fält och regler: docs/superpowers/specs/2026-10-20-appforsaljning-arket-design.md (pejl-repot).
 */
class SendPejlForwardTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fake-pejl-key-in-tests';

    private Game $glosis;

    private object $alerts;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pejl.api_url' => 'https://pejl.test', 'pejl.api_key' => self::KEY]);
        Queue::fake();
        $this->glosis = Game::where('slug', 'glosis')->firstOrFail();

        $this->alerts = new class extends SlackAlert
        {
            public array $sent = [];

            public function send(string $dedupeKey, string $text): string
            {
                $this->sent[] = [$dedupeKey, $text];

                return self::SENT;
            }
        };
        $this->app->instance(SlackAlert::class, $this->alerts);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 18:00', 'Europe/Stockholm'));
    }

    private function forward(array $overrides = []): PejlForward
    {
        return PejlForward::create(array_merge([
            'game_id' => $this->glosis->id,
            'event_id' => 'evt-'.uniqid(),
            'kind' => 'sale',
            'store' => 'APP_STORE',
            'transaction_id' => '2000000123456789',
            'external_reference' => 'APP_STORE:2000000123456789',
            'occurred_at' => CarbonImmutable::parse('2026-10-19 09:30', 'Europe/Stockholm')->utc(),
            'description' => 'Guldstjärnan 2026/27 · App Store · SE',
            'amount' => 99.0,
            'currency' => 'SEK',
            'original_amount' => 99.0,
            'original_currency' => 'SEK',
            'tax_percentage' => 0.2,
            'commission_percentage' => 0.15,
            'status' => 'pending',
        ], $overrides));
    }

    private function usdSale(array $overrides = []): PejlForward
    {
        return $this->forward(array_merge([
            'amount' => 10.0,
            'currency' => 'USD',
            'original_amount' => 11.0,
            'original_currency' => 'EUR',
        ], $overrides));
    }

    private function fakePejl(int $status = 201, mixed $body = ['revenue_item_id' => 1], array $riksbank = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pejl.test/*' => Http::response($body, $status),
            'api.riksbank.se/*' => fn (Request $r) => $riksbank === [] ? Http::response('', 204) : Http::response($riksbank),
        ]);
    }

    private function handle(PejlForward $forward): SendPejlForward
    {
        $job = (new SendPejlForward($forward->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    private function pejlRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://pejl.test'))->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_sek_sale_posts_exact_payload_and_becomes_sent(): void
    {
        $this->fakePejl();
        $forward = $this->forward();

        $this->handle($forward);

        $requests = $this->pejlRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('https://pejl.test/api/revenue-items', $requests[0]->url());
        $this->assertSame('Bearer '.self::KEY, $requests[0]->header('Authorization')[0]);
        $this->assertSame([
            'source' => 'glosis',
            'type' => 'sale',
            'external_reference' => 'APP_STORE:2000000123456789',
            'occurred_at' => '2026-10-19T09:30:00+02:00',
            'description' => 'Guldstjärnan 2026/27 · App Store · SE',
            'amount_ex_vat' => 79.2,
            'cost' => 14.85,
        ], $requests[0]->data());

        $forward->refresh();
        $this->assertSame('sent', $forward->status);
        $this->assertNotNull($forward->sent_at);
        $this->assertSame('79.20', $forward->amount_ex_vat_sek);
        $this->assertSame('14.85', $forward->cost_sek);
        $this->assertNull($forward->fx_rate);
    }

    public function test_usd_sale_converts_with_riksbank_rate_and_stores_it(): void
    {
        $this->fakePejl(riksbank: [['date' => '2026-10-19', 'value' => 10.50]]);
        $forward = $this->usdSale();

        $this->handle($forward);

        $data = $this->pejlRequests()[0]->data();
        $this->assertSame(84.0, $data['amount_ex_vat']);
        $this->assertSame(15.75, $data['cost']);

        $forward->refresh();
        $this->assertSame('sent', $forward->status);
        $this->assertSame('10.500000', $forward->fx_rate);
        $this->assertSame('2026-10-19', $forward->fx_date->toDateString());
    }

    public function test_fx_day_is_the_stockholm_date(): void
    {
        $this->fakePejl(riksbank: [['date' => '2026-10-16', 'value' => 10.00], ['date' => '2026-10-19', 'value' => 10.50]]);
        // 00:30 i Stockholm den 19:e är fortfarande den 18:e i UTC (en söndag).
        $forward = $this->usdSale(['occurred_at' => CarbonImmutable::parse('2026-10-19 00:30', 'Europe/Stockholm')->utc()]);

        $this->handle($forward);

        $this->assertSame('2026-10-19', $forward->refresh()->fx_date->toDateString());
        $this->assertSame('2026-10-19T00:30:00+02:00', $this->pejlRequests()[0]->data()['occurred_at']);
    }

    public function test_todays_rate_not_published_releases_without_attempt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Stockholm'));
        $this->fakePejl(riksbank: [['date' => '2026-10-19', 'value' => 10.50]]);
        $forward = $this->usdSale(['occurred_at' => CarbonImmutable::parse('2026-10-20 09:00', 'Europe/Stockholm')->utc()]);

        $job = $this->handle($forward);

        $job->assertReleased(delay: 1800);
        $forward->refresh();
        $this->assertSame('pending', $forward->status);
        $this->assertSame(0, $forward->attempts);
        $this->assertNull($forward->last_error);
        $this->assertCount(0, $this->pejlRequests());
        $this->assertGreaterThanOrEqual(now()->addHours(72)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
        $this->assertSame((string) $forward->id, (string) $job->uniqueId());
        $this->assertFalse(property_exists($job, 'tries'));
    }

    public function test_pejl_500_counts_attempt_and_throws(): void
    {
        $this->fakePejl(500, 'Server Error');
        $forward = $this->forward();

        try {
            $this->handle($forward);
            $this->fail('Jobbet skulle kasta för omförsök');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $forward->refresh();
        $this->assertSame('pending', $forward->status);
        $this->assertSame(1, $forward->attempts);
        $this->assertStringContainsString('HTTP 500', $forward->last_error);
        $this->assertSame([], $this->alerts->sent);
    }

    public function test_unreachable_pejl_counts_attempt_and_throws(): void
    {
        Http::preventStrayRequests();
        Http::fake(['pejl.test/*' => fn () => throw new ConnectionException('cURL error 28 for Bearer '.self::KEY)]);
        $forward = $this->forward();

        $this->expectException(\RuntimeException::class);
        try {
            $this->handle($forward);
        } finally {
            $forward->refresh();
            $this->assertSame(1, $forward->attempts);
            $this->assertSame('pending', $forward->status);
            $this->assertStringContainsString('cURL error 28', $forward->last_error);
            $this->assertStringNotContainsString(self::KEY, $forward->last_error);
        }
    }

    public function test_failed_after_last_retry_marks_failed_and_alerts(): void
    {
        $forward = $this->forward(['attempts' => 4, 'last_error' => 'HTTP 500: Server Error']);

        (new SendPejlForward($forward->id))->failed(new \RuntimeException('Pejl svarade HTTP 500'));

        $forward->refresh();
        $this->assertSame('failed', $forward->status);
        $this->assertSame('HTTP 500: Server Error', $forward->last_error);
        $this->assertCount(1, $this->alerts->sent);
        $this->assertSame("pejl-forward-{$forward->id}", $this->alerts->sent[0][0]);
    }

    public function test_422_fails_directly_and_alerts(): void
    {
        $this->fakePejl(422, ['message' => 'Unknown source glosis']);
        $forward = $this->forward();

        $job = $this->handle($forward);

        $job->assertNotReleased();
        $forward->refresh();
        $this->assertSame('failed', $forward->status);
        $this->assertSame(1, $forward->attempts);
        $this->assertStringContainsString('422', $forward->last_error);
        $this->assertStringContainsString('Unknown source', $forward->last_error);
        $this->assertCount(1, $this->alerts->sent);
        $this->assertSame("pejl-forward-{$forward->id}", $this->alerts->sent[0][0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authFailures')]
    public function test_401_and_403_fail_directly_and_alert(int $status): void
    {
        $this->fakePejl($status, ['message' => 'Nekad']);
        $forward = $this->forward();

        $job = $this->handle($forward);

        $job->assertNotReleased();
        $forward->refresh();
        $this->assertSame('failed', $forward->status);
        $this->assertStringContainsString("HTTP {$status}", $forward->last_error);
        $this->assertCount(1, $this->alerts->sent);
    }

    public static function authFailures(): array
    {
        return [[401], [403]];
    }

    public function test_unique_lock_expires_after_the_retry_window(): void
    {
        $this->assertSame(73 * 3600, (new SendPejlForward(1))->uniqueFor);
    }

    public function test_job_does_not_shadow_the_queue_fail_method(): void
    {
        $this->assertFalse((new \ReflectionMethod(SendPejlForward::class, 'fail'))->isPrivate());
        $this->assertTrue(method_exists(SendPejlForward::class, 'markFailed'));
    }

    public function test_key_is_never_logged_stored_or_alerted(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });
        // Pejl ekar nyckeln i svaret.
        $this->fakePejl(422, ['message' => 'Fel nyckel: Bearer '.self::KEY]);
        $forward = $this->forward();

        $this->handle($forward);

        $this->assertNotEmpty($logged);
        $everything = implode("\n", $logged)."\n".$forward->refresh()->last_error."\n".json_encode($this->alerts->sent);
        $this->assertStringNotContainsString(self::KEY, $everything);
        $this->assertStringContainsString('[redacted]', $forward->last_error);
        $this->assertStringContainsString('external_reference', implode("\n", $logged));
    }

    public function test_missing_config_fails_loud_without_posting(): void
    {
        config(['pejl.api_key' => null]);
        $this->fakePejl();
        $forward = $this->forward();

        $this->handle($forward);

        $forward->refresh();
        $this->assertSame('failed', $forward->status);
        $this->assertStringContainsString('PEJL_API_KEY', $forward->last_error);
        $this->assertCount(1, $this->alerts->sent);
        $this->assertCount(0, $this->pejlRequests());
    }

    public function test_row_that_is_not_pending_is_left_alone(): void
    {
        $this->fakePejl();
        $forward = $this->forward(['status' => 'sent', 'sent_at' => now()]);

        $this->handle($forward);

        $this->assertCount(0, $this->pejlRequests());
        $this->assertSame('sent', $forward->refresh()->status);
    }

    public function test_refund_after_sent_sale_uses_the_sales_rate(): void
    {
        // SWEA svarar med en annan kurs; den får inte användas.
        $this->fakePejl(riksbank: [['date' => '2026-10-19', 'value' => 9.00]]);
        $this->usdSale(['status' => 'sent', 'sent_at' => now(), 'fx_rate' => 10.5, 'fx_date' => '2026-10-16']);
        $refund = $this->usdSale([
            'kind' => 'refund',
            'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1',
            'description' => 'Återbetalning: Guldstjärnan 2026/27 · App Store · SE',
            'occurred_at' => CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Stockholm')->utc(),
            'fx_rate' => 10.5,
            'fx_date' => '2026-10-16',
        ]);

        $this->handle($refund);

        $data = $this->pejlRequests()[0]->data();
        $this->assertSame('refund', $data['type']);
        $this->assertSame(84.0, $data['amount_ex_vat']);
        $this->assertSame(15.75, $data['cost']);
        $this->assertSame('sent', $refund->refresh()->status);
        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'riksbank')));
    }

    public function test_refund_never_posts_before_its_sale_is_sent(): void
    {
        $this->fakePejl();
        $this->forward();
        $refund = $this->forward(['kind' => 'refund', 'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1']);

        $this->handle($refund);

        $this->assertCount(0, $this->pejlRequests());
        $this->assertSame('pending', $refund->refresh()->status);
    }

    public function test_pending_refund_is_sent_when_sale_becomes_sent(): void
    {
        $this->fakePejl(riksbank: [['date' => '2026-10-19', 'value' => 10.50]]);
        $sale = $this->usdSale();
        $refund = $this->usdSale([
            'kind' => 'refund',
            'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1',
            'amount' => 4.0,
            'occurred_at' => CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Stockholm')->utc(),
        ]);
        $other = $this->usdSale(['kind' => 'refund', 'transaction_id' => 'annan', 'external_reference' => 'APP_STORE:annan:refund:evt-x']);

        $this->handle($sale);

        $refund->refresh();
        $this->assertSame('10.500000', $refund->fx_rate);
        $this->assertSame('2026-10-19', $refund->fx_date->toDateString());
        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $refund->id);
        Queue::assertNotPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $other->id);

        $this->handle($refund);

        $data = $this->pejlRequests()[1]->data();
        $this->assertSame('refund', $data['type']);
        $this->assertSame(33.6, $data['amount_ex_vat']);
        $this->assertSame(6.3, $data['cost']);
        $this->assertSame('sent', $refund->refresh()->status);
    }

    public function test_pending_refund_without_amount_takes_the_whole_sale(): void
    {
        $this->fakePejl();
        $sale = $this->forward();
        $refund = $this->forward([
            'kind' => 'refund',
            'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1',
            'amount' => null, 'tax_percentage' => null, 'commission_percentage' => null, 'currency' => null,
        ]);

        $this->handle($sale);

        $refund->refresh();
        $this->assertSame('99.0000', $refund->amount);
        $this->assertSame('SEK', $refund->currency);
        $this->assertSame('0.200000', $refund->tax_percentage);
        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $refund->id);
    }

    public function test_pending_refund_over_the_cap_fails_and_alerts(): void
    {
        $this->fakePejl();
        $sale = $this->forward();
        $this->forward(['kind' => 'refund', 'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1', 'amount' => 60.0]);
        $second = $this->forward(['kind' => 'refund', 'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r2', 'amount' => 60.0]);

        $this->handle($sale);

        $this->assertSame('failed', $second->refresh()->status);
        $this->assertStringContainsString('större än köpet', $second->last_error);
        Queue::assertNotPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $second->id);
        Queue::assertPushed(SendPejlForward::class, 1);
        $this->assertSame("pejl-forward-{$second->id}", $this->alerts->sent[0][0]);
    }

    public function test_pending_reversal_is_sent_when_refund_becomes_sent(): void
    {
        $this->fakePejl();
        $this->forward(['status' => 'sent', 'sent_at' => now()]);
        $refund = $this->forward(['kind' => 'refund', 'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1', 'amount' => 99.0]);
        $reversal = $this->forward([
            'kind' => 'refund_reversed',
            'external_reference' => 'APP_STORE:2000000123456789:refund-reversed:evt-rr1',
            'amount' => null,
        ]);

        $this->handle($refund);

        $this->assertSame('99.0000', $reversal->refresh()->amount);
        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $reversal->id);

        $this->handle($reversal);

        $data = $this->pejlRequests()[1]->data();
        $this->assertSame('sale', $data['type']);
        $this->assertSame('APP_STORE:2000000123456789:refund-reversed:evt-rr1', $data['external_reference']);
        $this->assertSame(79.2, $data['amount_ex_vat']);
        $this->assertSame('sent', $reversal->refresh()->status);
    }

    public function test_pending_usd_refund_without_rate_takes_the_sales_terms(): void
    {
        $this->fakePejl(riksbank: [['date' => '2026-10-19', 'value' => 9.00]]);
        $this->usdSale(['status' => 'sent', 'sent_at' => now(), 'fx_rate' => 10.5, 'fx_date' => '2026-10-16']);
        $refund = $this->usdSale([
            'kind' => 'refund',
            'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1',
            'tax_percentage' => null,
            'commission_percentage' => null,
        ]);

        $this->handle($refund);

        $data = $this->pejlRequests()[0]->data();
        $this->assertSame(84.0, $data['amount_ex_vat']);
        $this->assertSame(15.75, $data['cost']);
        $refund->refresh();
        $this->assertSame('sent', $refund->status);
        $this->assertSame('10.500000', $refund->fx_rate);
        $this->assertSame('2026-10-16', $refund->fx_date->toDateString());
    }

    public function test_retry_failed_keeps_a_cap_failed_refund_unposted(): void
    {
        $this->fakePejl();
        $this->forward(['status' => 'sent', 'sent_at' => now()]);
        $this->forward(['kind' => 'refund', 'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r1', 'amount' => 60.0, 'status' => 'sent', 'sent_at' => now()]);
        $second = $this->forward(['kind' => 'refund', 'external_reference' => 'APP_STORE:2000000123456789:refund:evt-r2', 'amount' => 60.0, 'status' => 'failed', 'last_error' => 'Återbetalningen blir större än köpet']);

        $this->artisan('pejl:check-forwards', ['--retry-failed' => true])->assertSuccessful();
        $this->handle($second);

        $this->assertCount(0, $this->pejlRequests());
        $this->assertSame('failed', $second->refresh()->status);
        $this->assertStringContainsString('större än köpet', $second->last_error);
        $this->assertSame("pejl-forward-{$second->id}", $this->alerts->sent[0][0]);
    }

    public function test_retry_failed_keeps_a_row_with_bad_percentage_unposted(): void
    {
        $this->fakePejl();
        $forward = $this->forward(['commission_percentage' => 1.5, 'status' => 'failed', 'last_error' => 'Orimlig commission_percentage från RevenueCat: 1.5']);

        $this->artisan('pejl:check-forwards', ['--retry-failed' => true])->assertSuccessful();
        $this->handle($forward);

        $this->assertCount(0, $this->pejlRequests());
        $this->assertSame('failed', $forward->refresh()->status);
        $this->assertStringContainsString('commission_percentage', $forward->last_error);
        $this->assertCount(1, $this->alerts->sent);
    }

    public function test_retry_failed_keeps_a_row_without_amount_unposted(): void
    {
        $this->fakePejl();
        $forward = $this->forward(['amount' => null, 'status' => 'failed', 'last_error' => 'Saknar fält från RevenueCat: price_in_purchased_currency']);

        $this->artisan('pejl:check-forwards', ['--retry-failed' => true])->assertSuccessful();
        $this->handle($forward);

        $this->assertCount(0, $this->pejlRequests());
        $this->assertSame('failed', $forward->refresh()->status);
    }

    public function test_retry_failed_reposts_a_row_failed_for_401(): void
    {
        $this->fakePejl();
        $forward = $this->forward(['status' => 'failed', 'attempts' => 1, 'last_error' => 'HTTP 401: Unauthenticated']);

        $this->artisan('pejl:check-forwards', ['--retry-failed' => true])->assertSuccessful();
        Queue::assertPushed(SendPejlForward::class, fn ($job) => $job->forwardId === $forward->id);
        $this->handle($forward);

        $this->assertCount(1, $this->pejlRequests());
        $this->assertSame('sent', $forward->refresh()->status);
    }
}
