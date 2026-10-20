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

    private function runPushedJobs(): void
    {
        foreach (Queue::pushed(SendPejlForward::class) as $job) {
            app()->call([$job->withFakeQueueInteractions(), 'handle']);
        }
        Queue::fake();
    }

    private function fakeRevenuePost(int $status, array $body): void
    {
        Http::fake([
            'pejl.test/api/revenue-items' => Http::response($body, $status),
            'pejl.test/api/revenue-sources/*' => Http::response(['message' => 'Okänd källa.'], 404),
            'hooks.slack.test/*' => Http::response('ok'),
        ]);
    }

    private const UNKNOWN = ['message' => 'Okänd källa.', 'errors' => ['source' => ['Okänd källa.']]];

    public function test_waiting_rows_alert_once_per_game_and_are_redispatched(): void
    {
        $kattspel = Game::create(['slug' => 'kattspel', 'name' => 'Kattspel', 'is_active' => true, 'settings' => []]);
        $oldest = $this->forward(['status' => 'waiting_for_source', 'occurred_at' => CarbonImmutable::parse('2026-10-17 08:15', 'Europe/Stockholm')->utc()]);
        $oldest->forceFill(['created_at' => now()->subHours(30)])->save();
        $second = $this->forward(['status' => 'waiting_for_source']);
        $other = $this->forward(['game_id' => $kattspel->id, 'status' => 'waiting_for_source']);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertSame(['pejl-check-waiting-source-glosis-2026-10-20', 'pejl-check-waiting-source-kattspel-2026-10-20'], array_column($this->alerts->sent, 0));
        $text = $this->alerts->sent[0][1];
        $this->assertStringContainsString('2 köp', $text);
        $this->assertStringContainsString('2026-10-17 08:15', $text);
        $this->assertStringContainsString('Lägg till glosis i config/revenue_sources.php i Pejl', $text);
        $this->assertStringContainsString('1 köp', $this->alerts->sent[1][1]);
        foreach ([$oldest, $second, $other] as $forward) {
            $this->assertSame('pending', $forward->refresh()->status);
            Queue::assertPushed(SendPejlForward::class, fn ($j) => $j->forwardId === $forward->id);
        }
        Queue::assertPushed(SendPejlForward::class, 3);
        Http::assertNothingSent();
    }

    public function test_refund_waiting_on_a_waiting_sale_is_not_stuck(): void
    {
        $sale = $this->forward(['status' => 'waiting_for_source']);
        $refund = $this->forward(['kind' => 'refund', 'status' => 'pending', 'transaction_id' => $sale->transaction_id, 'external_reference' => "{$sale->external_reference}:refund:r1"]);
        $refund->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertSame(['pejl-check-waiting-source-glosis-2026-10-20'], array_column($this->alerts->sent, 0));
    }

    public function test_still_unknown_rows_return_silently_and_the_alert_is_sent_once_a_day(): void
    {
        config(['provisioning.slack_webhook_url' => 'https://hooks.slack.test/fake']);
        $this->app->instance(SlackAlert::class, new SlackAlert);
        $this->fakeRevenuePost(422, self::UNKNOWN);
        $forward = $this->forward(['status' => 'waiting_for_source']);
        $slackPosts = fn () => Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://hooks.slack.test'))->count();

        $this->artisan('pejl:check-forwards')->assertExitCode(1);
        $this->runPushedJobs();
        $this->assertSame('waiting_for_source', $forward->refresh()->status);
        $this->assertSame(1, $slackPosts());

        $this->artisan('pejl:check-forwards')->assertExitCode(1);
        $this->runPushedJobs();
        $this->assertSame('waiting_for_source', $forward->refresh()->status);
        $this->assertSame(1, $slackPosts());

        $this->travel(1)->day();
        $this->artisan('pejl:check-forwards')->assertExitCode(1);
        $this->assertSame(2, $slackPosts());
    }

    public function test_waiting_rows_are_sent_once_the_source_exists(): void
    {
        $this->fakeRevenuePost(201, ['revenue_item_id' => 1]);
        $forward = $this->forward(['status' => 'waiting_for_source', 'amount_ex_vat_sek' => null, 'cost_sek' => null]);

        $this->artisan('pejl:check-forwards');
        $this->runPushedJobs();

        $forward->refresh();
        $this->assertSame('sent', $forward->status);
        $this->assertNull($forward->last_error);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://pejl.test/api/revenue-items' && $r['source'] === 'glosis');
    }

    public function test_reconciliation_skips_a_game_with_only_waiting_rows(): void
    {
        $this->forward(['status' => 'waiting_for_source']);
        $this->fakeSummaries([], 404);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $this->assertSame(['pejl-check-waiting-source-glosis-2026-10-20'], array_column($this->alerts->sent, 0));
        Http::assertNothingSent();
    }

    public function test_404_for_a_game_with_sent_rows_still_alerts(): void
    {
        $this->forward();
        $this->forward(['status' => 'waiting_for_source']);
        $this->fakeSummaries([], 404);

        $this->artisan('pejl:check-forwards')->assertExitCode(1);

        $keys = array_column($this->alerts->sent, 0);
        $this->assertContains('pejl-check-error-glosis', $keys);
        $this->assertStringContainsString('404', $this->alerts->sent[array_search('pejl-check-error-glosis', $keys)][1]);
    }

    public function test_retry_failed_leaves_waiting_rows_alone(): void
    {
        $waiting = $this->forward(['status' => 'waiting_for_source']);

        $this->artisan('pejl:check-forwards', ['--retry-failed' => true])->assertExitCode(0);

        $this->assertSame('waiting_for_source', $waiting->refresh()->status);
        Queue::assertNothingPushed();
    }
}
