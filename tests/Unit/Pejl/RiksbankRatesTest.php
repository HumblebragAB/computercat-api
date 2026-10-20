<?php

namespace Tests\Unit\Pejl;

use App\Services\Pejl\FxRateUnavailable;
use App\Services\Pejl\RiksbankRates;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RiksbankRatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function week(): array
    {
        return [
            ['date' => '2026-09-25', 'value' => 9.40],
            ['date' => '2026-09-28', 'value' => 9.45],
            ['date' => '2026-09-29', 'value' => 9.50],
            ['date' => '2026-09-30', 'value' => 9.55],
            ['date' => '2026-10-01', 'value' => 9.60],
        ];
    }

    private function fakeRiksbank(array $observations, int $status = 200): void
    {
        Http::fake(['api.riksbank.se/*' => function (Request $r) use ($observations, $status) {
            if ($status !== 200) {
                return Http::response(['statusCode' => $status, 'message' => 'Rate limit is exceeded.'], $status);
            }
            $to = basename($r->url());
            $rows = array_values(array_filter($observations, fn ($o) => $o['date'] <= $to));

            return $rows === [] ? Http::response('', 204) : Http::response($rows);
        }]);
    }

    private function sthlm(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Europe/Stockholm');
    }

    private function rates(): RiksbankRates
    {
        return app(RiksbankRates::class);
    }

    public function test_weekday_uses_that_days_usd_rate(): void
    {
        $this->fakeRiksbank($this->week());
        $this->travelTo($this->sthlm('2026-10-01 18:00'));

        $q = $this->rates()->rateFor('usd', $this->sthlm('2026-10-01 09:00'));

        $this->assertSame(['USD', 9.60, '2026-10-01'], [$q->currency, $q->rate, $q->date->toDateString()]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.riksbank.se/swea/v1/Observations/SEKUSDPMI/2026-09-24/2026-10-01');
    }

    public function test_saturday_uses_fridays_rate(): void
    {
        $this->fakeRiksbank($this->week());
        $this->travelTo($this->sthlm('2026-09-28 18:00'));

        $q = $this->rates()->rateFor('USD', $this->sthlm('2026-09-26 12:00'));

        $this->assertSame([9.40, '2026-09-25'], [$q->rate, $q->date->toDateString()]);
    }

    public function test_todays_unpublished_rate_is_pending(): void
    {
        $this->fakeRiksbank(array_slice($this->week(), 0, 4));
        $this->travelTo($this->sthlm('2026-10-01 10:00'));

        try {
            $this->rates()->rateFor('USD', $this->sthlm('2026-10-01 09:00'));
            $this->fail('förväntade FxRateUnavailable');
        } catch (FxRateUnavailable $e) {
            $this->assertTrue($e->pending);
        }
    }

    public function test_unpublished_day_is_asked_at_most_every_ten_minutes(): void
    {
        $this->fakeRiksbank(array_slice($this->week(), 0, 4));
        $this->travelTo($this->sthlm('2026-10-01 10:00'));
        $ask = fn () => rescue(fn () => $this->rates()->rateFor('USD', $this->sthlm('2026-10-01 09:00')), report: false);

        $ask();
        $ask();
        $this->travel(11)->minutes();
        $ask();

        Http::assertSentCount(2);
    }

    public function test_no_rate_in_lookback_for_past_day_is_missing(): void
    {
        $this->fakeRiksbank([]);
        $this->travelTo($this->sthlm('2026-10-01 10:00'));

        try {
            $this->rates()->rateFor('USD', $this->sthlm('2026-09-20 12:00'));
            $this->fail('förväntade FxRateUnavailable');
        } catch (FxRateUnavailable $e) {
            $this->assertFalse($e->pending);
            $this->assertStringContainsString('2026-09-13', $e->getMessage());
        }
    }

    public function test_503_is_pending_logged_and_backs_off(): void
    {
        Log::spy();
        $this->fakeRiksbank([], 503);
        $this->travelTo($this->sthlm('2026-10-01 18:00'));
        $ask = fn () => $this->rates()->rateFor('USD', $this->sthlm('2026-09-30 12:00'));

        foreach ([1, 2] as $_) {
            try {
                $ask();
                $this->fail('förväntade FxRateUnavailable');
            } catch (FxRateUnavailable $e) {
                $this->assertTrue($e->pending);
            }
        }

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->once()->with('Riksbank answered HTTP 503', \Mockery::on(fn ($c) => str_contains($c['body'], 'statusCode')));

        $this->travel(61)->seconds();
        try {
            $ask();
        } catch (FxRateUnavailable) {
        }
        Http::assertSentCount(2);
    }

    public function test_rate_outside_sanity_band_throws(): void
    {
        $this->fakeRiksbank([['date' => '2026-09-30', 'value' => 1.0]]);
        $this->travelTo($this->sthlm('2026-10-01 18:00'));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('sanity band');
        $this->rates()->rateFor('USD', $this->sthlm('2026-09-30 12:00'));
    }

    public function test_currency_without_series_throws(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->rates()->rateFor('JPY', $this->sthlm('2026-09-30 12:00'));
    }

    public function test_found_rate_is_cached(): void
    {
        $this->fakeRiksbank($this->week());
        $this->travelTo($this->sthlm('2026-10-01 18:00'));

        $this->rates()->rateFor('USD', $this->sthlm('2026-09-30 08:00'));
        $this->rates()->rateFor('USD', $this->sthlm('2026-09-30 20:00'));

        Http::assertSentCount(1);
    }

    public function test_stockholm_midnight_decides_the_day(): void
    {
        $this->fakeRiksbank($this->week());
        $this->travelTo($this->sthlm('2026-10-01 18:00'));

        $q = $this->rates()->rateFor('USD', CarbonImmutable::parse('2026-09-30T22:30:00Z'));

        $this->assertSame('2026-10-01', $q->date->toDateString());
    }

    public function test_future_payment_throws(): void
    {
        $this->travelTo($this->sthlm('2026-10-01 10:00'));

        $this->expectException(\UnexpectedValueException::class);
        $this->rates()->rateFor('USD', $this->sthlm('2026-10-02 10:00'));
    }

    public function test_unexpected_4xx_throws_not_pending(): void
    {
        $this->fakeRiksbank([], 404);
        $this->travelTo($this->sthlm('2026-10-01 18:00'));

        $this->expectException(\UnexpectedValueException::class);
        $this->rates()->rateFor('USD', $this->sthlm('2026-09-30 12:00'));
    }
}
