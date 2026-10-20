<?php

namespace App\Jobs;

use App\Models\PejlForward;
use App\Services\Pejl\ForwardRecorder;
use App\Services\Pejl\FxRateUnavailable;
use App\Services\Pejl\PejlClient;
use App\Services\Pejl\RiksbankRates;
use App\Support\SlackAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

// Postar en PejlForward till Pejls POST /api/revenue-items (specen 2026-10-20-appforsaljning-arket-design.md).
// Ingen $tries: retryUntil() sätter gränsen i tid, eftersom varje release() räknas som ett försök
// och en ej publicerad dagskurs kan vänta över en helg. Pejl 4xx/5xx/onåbar kastar för omförsök med
// backoff; 401/403/422 (fel nyckel, saknat scope, fel i data eller konfiguration) och saknad PEJL_API_URL/KEY fäller raden direkt.
// Undantag: 422 "Okänd källa." (spelet saknas i Pejls config/revenue_sources.php) ger waiting_for_source utan larm.
// Före varje post prövas registreringens kontroller om (ForwardRecorder::postingError), eftersom --retry-failed sätter tillbaka alla failed-rader.
// failed() fäller raden och larmar. Unikt per rad, så att samma rad aldrig postas av två jobb.
class SendPejlForward implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FX_PENDING_DELAY = 1800;

    public const UNKNOWN_SOURCE = 'Okänd källa.';

    // Strax över retryUntil: ett försvunnet jobb ska inte blockera ny dispatch för alltid.
    public int $uniqueFor = 73 * 3600;

    public function __construct(public int $forwardId) {}

    public function uniqueId(): string
    {
        return (string) $this->forwardId;
    }

    public function retryUntil(): Carbon
    {
        return now()->addHours(72);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(RiksbankRates $rates, PejlClient $client, ForwardRecorder $recorder): void
    {
        $forward = PejlForward::with('game')->find($this->forwardId);
        if (! $forward || $forward->status !== 'pending' || ! $this->ready($forward)) {
            return;
        }

        if ($forward->kind !== 'sale') {
            $recorder->completeTerms($forward, $this->sentSale($forward));
        }

        if ($missing = $client->missingConfig()) {
            $this->markFailed($forward, implode(', ', $missing).' saknas i miljön; inget skickat till Pejl');

            return;
        }

        if ($error = $recorder->postingError($forward)) {
            $this->markFailed($forward, $error);

            return;
        }

        try {
            $rate = $this->rate($forward, $rates);
        } catch (FxRateUnavailable $e) {
            if ($e->pending) {
                $this->release(self::FX_PENDING_DELAY);

                return;
            }
            $this->recordError($forward, 'Valutakurs saknas: '.$e->getMessage());

            throw $e;
        } catch (\UnexpectedValueException $e) {
            $this->markFailed($forward, 'Valutakurs: '.$e->getMessage());

            return;
        }

        $amount = (float) $forward->amount;
        $sek = [
            'amount_ex_vat_sek' => round($amount * (1 - (float) $forward->tax_percentage) * $rate, 2),
            'cost_sek' => round($amount * (float) $forward->commission_percentage * $rate, 2),
        ];
        $forward->update($sek);

        $payload = [
            'source' => $forward->game->slug,
            'type' => $forward->pejlType(),
            'external_reference' => $forward->external_reference,
            'occurred_at' => $forward->occurred_at->copy()->setTimezone(config('pejl.timezone'))->toIso8601String(),
            'description' => $forward->description,
            'amount_ex_vat' => $sek['amount_ex_vat_sek'],
            'cost' => $sek['cost_sek'],
        ];

        try {
            $response = $client->postRevenue($payload);
        } catch (Throwable $e) {
            $this->recordError($forward, 'Pejl nåddes inte: '.$client->redact($e->getMessage()));

            throw new \RuntimeException("Pejl nåddes inte för vidarebefordran {$forward->id}");
        }

        if ($response['ok']) {
            if (PejlForward::whereKey($forward->id)->where('status', 'pending')->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]) === 1) {
                $recorder->releaseDependents($forward->refresh());
            }

            return;
        }

        if ($response['status'] === 422 && $this->unknownSource($response['body'])) {
            $this->markWaitingForSource($forward);

            return;
        }

        $error = "HTTP {$response['status']}: {$response['body']}";
        if (in_array($response['status'], [401, 403, 422], true)) {
            $this->markFailed($forward, $error, countAttempt: true);

            return;
        }

        $this->recordError($forward, $error);

        throw new \RuntimeException("Pejl svarade HTTP {$response['status']} för vidarebefordran {$forward->id}");
    }

    public function failed(?Throwable $e): void
    {
        $forward = PejlForward::find($this->forwardId);
        if (! $forward || $forward->status !== 'pending') {
            return;
        }

        $error = $forward->last_error ?? app(PejlClient::class)->redact($e?->getMessage() ?? 'Jobbet gav upp');
        $this->markFailed($forward, $error);
    }

    // En återbetalning väntar på att köpet skickats; en återkallelse på sitt belopp.
    private function ready(PejlForward $forward): bool
    {
        if ($forward->kind === 'sale') {
            return true;
        }

        return $this->sentSale($forward) !== null && $forward->amount !== null;
    }

    private function sentSale(PejlForward $forward): ?PejlForward
    {
        return PejlForward::where('game_id', $forward->game_id)
            ->where('store', $forward->store)
            ->where('transaction_id', $forward->transaction_id)
            ->where('kind', 'sale')
            ->where('status', 'sent')
            ->first();
    }

    // SEK per enhet. Köpet sparar sin kurs (villkorligt, en gång); återbetalningar har köpets kurs.
    private function rate(PejlForward $forward, RiksbankRates $rates): float
    {
        if ($forward->currency === 'SEK') {
            return 1.0;
        }
        if ($forward->fx_rate !== null) {
            return (float) $forward->fx_rate;
        }
        if ($forward->kind !== 'sale') {
            throw new \UnexpectedValueException('köpets kurs saknas på '.$forward->kind);
        }

        $quote = $rates->rateFor($forward->currency, $forward->occurred_at);
        PejlForward::whereKey($forward->id)->whereNull('fx_rate')->update(['fx_rate' => $quote->rate, 'fx_date' => $quote->date->toDateString()]);

        return (float) $forward->refresh()->fx_rate;
    }

    private function recordError(PejlForward $forward, string $error): void
    {
        $forward->increment('attempts', 1, ['last_error' => app(PejlClient::class)->redact($error)]);
    }

    // Pejls svar när spelet saknas i config/revenue_sources.php (RecordSourceRevenueRequest, source.in).
    private function unknownSource(?string $body): bool
    {
        $errors = json_decode((string) $body, true)['errors']['source'] ?? null;

        return is_array($errors) && in_array(self::UNKNOWN_SOURCE, $errors, true);
    }

    // Inget larm per köp: pejl:check-forwards larmar en gång per spel och dygn och skickar raderna igen.
    private function markWaitingForSource(PejlForward $forward): void
    {
        $updated = PejlForward::whereKey($forward->id)->where('status', 'pending')->update([
            'status' => 'waiting_for_source',
            'last_error' => "Spelet {$forward->game->slug} saknas som källa i Pejl",
            'attempts' => $forward->attempts + 1,
        ]);
        if ($updated === 1) {
            Log::warning('Pejl: spelet saknas som källa, raden väntar', ['forward_id' => $forward->id, 'game' => $forward->game->slug]);
        }
    }

    private function markFailed(PejlForward $forward, string $error, bool $countAttempt = false): void
    {
        $error = app(PejlClient::class)->redact($error);
        $updated = PejlForward::whereKey($forward->id)->where('status', 'pending')->update(array_filter([
            'status' => 'failed',
            'last_error' => $error,
            'attempts' => $countAttempt ? $forward->attempts + 1 : null,
        ], fn ($v) => $v !== null));
        if ($updated !== 1) {
            return;
        }

        Log::error('Pejl: vidarebefordran fälld', ['forward_id' => $forward->id, 'reference' => $forward->external_reference, 'error' => $error]);
        app(SlackAlert::class)->send(
            "pejl-forward-{$forward->id}",
            SlackAlert::escape("Pejl: {$forward->external_reference} skickades inte till Pejl. ".mb_strcut($error, 0, 500)),
        );
    }
}
