<?php

namespace App\Services\Pejl;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Riksbankens dagskurs för en köpdag (samma regler som laravel-shop). Dagens kurs som ännu inte
// publicerats är pending, en passerad dag utan egen kurs tar närmast föregående bankdag inom
// lookback_days. En hittad kurs cachas för alltid. 429/5xx/onåbar är pending med 60 s backoff.
final class RiksbankRates
{
    public const CLOCK_SKEW_SECONDS = 300;

    public const BACKOFF_SECONDS = 60;

    private const BACKOFF_KEY = 'pejl.fx.backoff';

    public function rateFor(string $currency, CarbonInterface $at): FxQuote
    {
        $currency = strtoupper($currency);
        $series = config("pejl.riksbank.series.{$currency}");
        if (! is_string($series) || $series === '') {
            throw new \UnexpectedValueException("No Riksbank series is configured for {$currency} (pejl.riksbank.series).");
        }

        $tz = (string) config('pejl.timezone', 'Europe/Stockholm');
        $now = CarbonImmutable::now($tz);
        $paid = CarbonImmutable::instance($at)->setTimezone($tz);
        if ($paid->greaterThan($now->addSeconds(self::CLOCK_SKEW_SECONDS))) {
            throw new \UnexpectedValueException("A payment dated {$paid->toDateTimeString()} is in the future.");
        }
        $day = $paid->min($now)->startOfDay();
        $today = $now->startOfDay();

        $cacheKey = "pejl.fx.{$series}.{$day->toDateString()}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return new FxQuote($currency, (float) $cached['rate'], CarbonImmutable::parse($cached['date'], $tz));
        }
        $pendingKey = "pejl.fx.pending.{$series}.{$day->toDateString()}";
        if (Cache::has($pendingKey)) {
            throw FxRateUnavailable::pending("{$series} for {$day->toDateString()} was not published at the last check.");
        }

        if (Cache::has(self::BACKOFF_KEY)) {
            throw FxRateUnavailable::pending('The Riksbank answered 429 or 5xx less than a minute ago.');
        }

        $from = $day->subDays((int) config('pejl.riksbank.lookback_days', 7));
        $url = rtrim((string) config('pejl.riksbank.base_url'), '/')."/Observations/{$series}/{$from->toDateString()}/{$day->toDateString()}";

        try {
            $response = Http::acceptJson()->timeout((int) config('pejl.riksbank.timeout', 10))->get($url);
        } catch (ConnectionException $e) {
            Log::warning('The Riksbank could not be reached', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw FxRateUnavailable::pending('The Riksbank could not be reached: '.$e->getMessage());
        }

        if ($response->status() === 204) {
            $observations = [];
        } elseif ($response->successful()) {
            $observations = $response->json();
        } else {
            $status = $response->status();
            Log::warning('Riksbank answered HTTP '.$status, [
                'url' => $url,
                'body' => mb_strcut($response->body(), 0, 4096),
            ]);

            if ($status === 429 || $status >= 500) {
                Cache::put(self::BACKOFF_KEY, true, now()->addSeconds(self::BACKOFF_SECONDS));

                throw FxRateUnavailable::pending('Riksbank answered HTTP '.$status);
            }

            throw new \UnexpectedValueException("Riksbank answered HTTP {$status} for {$series}: the request is wrong, not the timing.");
        }

        if (! is_array($observations) || ! array_is_list($observations)) {
            throw new \UnexpectedValueException("The Riksbank's observations for {$series} are not a list.");
        }

        $best = null;
        foreach ($observations as $observation) {
            if (! is_array($observation) || ! is_string($observation['date'] ?? null) || ! is_numeric($observation['value'] ?? null)) {
                throw new \UnexpectedValueException("A Riksbank observation for {$series} has no date and value.");
            }
            if ($observation['date'] <= $day->toDateString() && ($best === null || $observation['date'] > $best['date'])) {
                $best = $observation;
            }
        }

        if ($day->equalTo($today) && ($best === null || $best['date'] !== $day->toDateString())) {
            Cache::put($pendingKey, true, now()->addMinutes(10));

            throw FxRateUnavailable::pending("{$series} for {$day->toDateString()} is not published yet.");
        }

        if ($best === null) {
            throw FxRateUnavailable::missing("No {$series} rate between {$from->toDateString()} and {$day->toDateString()}.");
        }

        $rate = (float) $best['value'];
        [$min, $max] = config("pejl.riksbank.bounds.{$currency}", [0.0, INF]);
        if ($rate < $min || $rate > $max) {
            throw new \UnexpectedValueException("Riksbank {$series} {$best['date']} = {$rate} is outside the sanity band {$min}–{$max}.");
        }

        Cache::forever($cacheKey, ['rate' => $rate, 'date' => $best['date']]);

        return new FxQuote($currency, $rate, CarbonImmutable::parse($best['date'], $tz));
    }
}
