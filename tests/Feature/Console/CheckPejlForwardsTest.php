<?php

namespace Tests\Feature\Console;

use App\Jobs\SendPejlForward;
use App\Models\Game;
use App\Models\PejlForward;
use App\Support\SlackAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * pejl:check-forwards: gamla ej skickade rader och avstämning mot Pejls summor per källa och månad.
 */
class CheckPejlForwardsTest extends TestCase
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
        Http::preventStrayRequests();
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

        $this->travelTo(CarbonImmutable::parse('2026-10-20 06:30', 'Europe/Stockholm'));
    }

    private function forward(array $overrides = []): PejlForward
    {
        $n = uniqid();

        return PejlForward::create(array_merge([
            'game_id' => $this->glosis->id,
            'event_id' => "evt-$n",
            'kind' => 'sale',
            'store' => 'APP_STORE',
            'transaction_id' => $n,
            'external_reference' => "APP_STORE:$n",
            'occurred_at' => CarbonImmutable::parse('2026-10-19 09:30', 'Europe/Stockholm')->utc(),
            'amount' => 100.0,
            'currency' => 'SEK',
            'tax_percentage' => 0.2,
            'commission_percentage' => 0.15,
            'amount_ex_vat_sek' => 80.0,
            'cost_sek' => 15.0,
            'status' => 'sent',
        ], $overrides));
    }

    private function fakeSummaries(array $byMonth, int $status = 200): void
    {
        Http::fake(['pejl.test/api/revenue-sources/*' => function (Request $r) use ($byMonth, $status) {
            preg_match('#revenue-sources/([^/]+)/(\d+)/(\d+)$#', $r->url(), $m);
            $row = $byMonth[sprintf('%d-%02d', $m[2], $m[3])] ?? ['rows' => 0, 'price' => 0, 'cost' => 0];

            return Http::response($status === 200 ? ['source' => $m[1], 'year' => (int) $m[2], 'month' => (int) $m[3]] + $row : 'Serverfel', $status);
        }]);
    }

    public function test_nothing_to_alert_exits_zero_without_slack(): void
    {
        $this->forward();
        $this->forward(['kind' => 'refund', 'amount_ex_vat_sek' => 20.0, 'cost_sek' => 3.0]);
        $this->fakeSummaries(['2026-10' => ['rows' => 2, 'price' => 60.0, 'cost' => 12.0]]);

        $this->artisan('pejl:check-forwards')->assertExitCode(0);

        $this->assertSame([], $this->alerts->sent);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://pejl.test/api/revenue-sources/glosis/2026/10' && $r->header('Authorization')[0] === 'Bearer '.self::KEY);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://pejl.test/api/revenue-sources/glosis/2026/9');
    }

    public function test_old_failed_row_alerts_with_count_and_oldest_id(): void
    {
        $old = $this->forward(['status' => 'failed', 'amount_ex_vat_sek' => null, 'cost_sek' => null]);
        $old->forceFill(['created_at' => now()->subHours(30)])->save();
        $this->fakeSummaries([]);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertCount(1, $this->alerts->sent);
        $this->assertStringContainsString('1', $this->alerts->sent[0][1]);
        $this->assertStringContainsString("#{$old->id}", $this->alerts->sent[0][1]);
    }

    public function test_recent_failed_row_does_not_alert(): void
    {
        $this->forward(['status' => 'failed', 'amount_ex_vat_sek' => null, 'cost_sek' => null]);
        $this->fakeSummaries([]);

        $this->artisan('pejl:check-forwards')->assertExitCode(0);

        $this->assertSame([], $this->alerts->sent);
    }

    public function test_old_pending_row_is_redispatched_and_alerted_after_a_day(): void
    {
        $stale = $this->forward(['status' => 'pending', 'amount_ex_vat_sek' => null, 'cost_sek' => null]);
        $stale->forceFill(['created_at' => now()->subHours(2)])->save();
        $fresh = $this->forward(['status' => 'pending', 'amount_ex_vat_sek' => null, 'cost_sek' => null]);
        $this->fakeSummaries([]);

        $this->artisan('pejl:check-forwards')->assertExitCode(0);

        Queue::assertPushed(SendPejlForward::class, 1);
        Queue::assertPushed(SendPejlForward::class, fn ($j) => $j->forwardId === $stale->id);
        $this->assertSame([], $this->alerts->sent);

        $stale->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->artisan('pejl:check-forwards')->assertExitCode(1);
        $this->assertCount(1, $this->alerts->sent);
    }

    public function test_diverging_sum_alerts_with_both_sides(): void
    {
        $this->forward();
        $this->fakeSummaries(['2026-10' => ['rows' => 1, 'price' => 70.0, 'cost' => 15.0]]);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertCount(1, $this->alerts->sent);
        $text = $this->alerts->sent[0][1];
        $this->assertStringContainsString('glosis', $text);
        $this->assertStringContainsString('2026-10', $text);
        $this->assertStringContainsString('80', $text);
        $this->assertStringContainsString('70', $text);
    }

    public function test_wrong_row_count_alerts(): void
    {
        $this->forward();
        $this->fakeSummaries(['2026-10' => ['rows' => 2, 'price' => 80.0, 'cost' => 15.0]]);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertCount(1, $this->alerts->sent);
    }

    public function test_pejl_having_rows_we_lack_alerts(): void
    {
        $this->forward();
        $this->fakeSummaries([
            '2026-10' => ['rows' => 1, 'price' => 80.0, 'cost' => 15.0],
            '2026-09' => ['rows' => 1, 'price' => 5.0, 'cost' => 1.0],
        ]);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertStringContainsString('2026-09', $this->alerts->sent[0][1]);
    }

    public function test_difference_within_a_cent_is_fine(): void
    {
        $this->forward();
        $this->fakeSummaries(['2026-10' => ['rows' => 1, 'price' => 80.005, 'cost' => 15.0]]);

        $this->artisan('pejl:check-forwards')->assertExitCode(0);
    }

    public function test_pejl_500_alerts_that_reconciliation_could_not_run(): void
    {
        $this->forward();
        $this->fakeSummaries([], 500);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertCount(1, $this->alerts->sent);
        $this->assertStringContainsString('avstämningen kunde inte köras', $this->alerts->sent[0][1]);
        $this->assertStringNotContainsString(self::KEY, $this->alerts->sent[0][1]);
    }

    public function test_missing_config_alerts_that_reconciliation_could_not_run(): void
    {
        config(['pejl.api_key' => null]);
        $this->forward();

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertStringContainsString('avstämningen kunde inte köras', $this->alerts->sent[0][1]);
    }

    public function test_purchase_late_on_the_31st_utc_counts_in_november(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 06:30', 'Europe/Stockholm'));
        $this->forward(['occurred_at' => CarbonImmutable::parse('2026-10-31 23:30', 'UTC')]);
        $this->fakeSummaries(['2026-11' => ['rows' => 1, 'price' => 80.0, 'cost' => 15.0]]);

        $this->artisan('pejl:check-forwards')->assertExitCode(0);

        $this->assertSame([], $this->alerts->sent);
    }

    public function test_month_option_checks_only_that_month(): void
    {
        $this->forward(['occurred_at' => CarbonImmutable::parse('2026-08-10 10:00', 'Europe/Stockholm')->utc()]);
        $this->fakeSummaries(['2026-08' => ['rows' => 1, 'price' => 80.0, 'cost' => 15.0]]);

        $this->artisan('pejl:check-forwards', ['--month' => '2026-08'])->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_retry_failed_resets_every_failed_row_and_dispatches_it(): void
    {
        $a = $this->forward(['status' => 'failed', 'attempts' => 3, 'last_error' => 'HTTP 403: nekad']);
        $b = $this->forward(['status' => 'failed', 'attempts' => 1, 'last_error' => 'HTTP 401: nekad']);
        $sent = $this->forward();

        $this->artisan('pejl:check-forwards', ['--retry-failed' => true])
            ->expectsOutputToContain('2')
            ->assertExitCode(0);

        $a->refresh();
        $this->assertSame('pending', $a->status);
        $this->assertSame(3, $a->attempts);
        $this->assertSame('HTTP 403: nekad', $a->last_error);
        $this->assertSame('pending', $b->refresh()->status);
        $this->assertSame('sent', $sent->refresh()->status);
        Queue::assertPushed(SendPejlForward::class, 2);
        Http::assertNothingSent();
    }
}
