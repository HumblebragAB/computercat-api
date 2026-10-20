<?php

namespace App\Services\Pejl;

use App\Jobs\SendPejlForward;
use App\Models\Game;
use App\Models\PejlForward;
use App\Models\Product;
use App\Services\RevenueCat\RefundRule;
use App\Support\SlackAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

// Registrerar en RevenueCat-händelse som ska vidarebefordras till Pejl (en rad i pejl_forwards)
// och köar försändelsen. Regler och fältnamn: specen 2026-10-20-appforsaljning-arket-design.md.
// Raden sparar belopp i ursprungsvaluta; SEK räknas fram i SendPejlForward. occurred_at sparas
// i UTC som övriga tidsstämplar och formateras i config('pejl.timezone') när den skickas.
class ForwardRecorder
{
    private const SALE_TYPES = ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'RENEWAL'];

    private const STORES = ['APP_STORE' => 'App Store', 'MAC_APP_STORE' => 'App Store', 'PLAY_STORE' => 'Google Play'];

    public function record(Game $game, array $event): ?PejlForward
    {
        $kind = $this->kind($event);
        if ($kind === null || ($event['environment'] ?? null) !== 'PRODUCTION') {
            return null;
        }

        $store = $event['store'] ?? null;
        if (! is_string($store) || ! isset(self::STORES[$store])) {
            Log::info('Pejl: butiken vidarebefordras inte', ['game' => $game->slug, 'store' => $store, 'event_id' => $event['id'] ?? null]);

            return null;
        }

        // Gratis provperiod: inget att bokföra.
        if ($kind === 'sale' && is_numeric($event['price_in_purchased_currency'] ?? null) && (float) $event['price_in_purchased_currency'] === 0.0) {
            return null;
        }

        $eventId = $this->requiredString($event, 'id');
        $transactionId = $this->requiredString($event, 'transaction_id');
        $reference = match ($kind) {
            'sale' => "{$store}:{$transactionId}",
            'refund' => "{$store}:{$transactionId}:refund:{$eventId}",
            'refund_reversed' => "{$store}:{$transactionId}:refund-reversed:{$eventId}",
        };

        if ($existing = PejlForward::where('external_reference', $reference)->first()) {
            return $existing;
        }

        $base = [
            'game_id' => $game->id,
            'event_id' => $eventId,
            'kind' => $kind,
            'store' => $store,
            'transaction_id' => $transactionId,
            'external_reference' => $reference,
            'occurred_at' => $this->occurredAt($kind, $event),
            'description' => ($kind === 'refund' ? 'Återbetalning: ' : '').$this->description($game, $store, $event),
        ];

        [$attributes, $ready] = match ($kind) {
            'sale' => $this->saleAttributes($event),
            'refund' => $this->refundAttributes($game, $store, $transactionId, $event),
            'refund_reversed' => $this->reversalAttributes($game, $store, $transactionId),
        };

        if ($base['occurred_at'] === null) {
            $attributes = ['status' => 'failed', 'last_error' => 'Saknar fält från RevenueCat: '.($kind === 'sale' ? 'purchased_at_ms' : 'event_timestamp_ms')] + $attributes;
            $ready = false;
        }

        try {
            $forward = PejlForward::create($base + $attributes);
        } catch (UniqueConstraintViolationException) {
            // Samtidig omleverans av samma händelse: den andra förfrågan hann först.
            return PejlForward::where('external_reference', $reference)->firstOrFail();
        }

        if ($forward->status === 'failed') {
            Log::error('Pejl: vidarebefordran kunde inte registreras', ['forward_id' => $forward->id, 'reference' => $reference, 'error' => $forward->last_error]);
            app(SlackAlert::class)->send("pejl-forward-{$forward->id}", SlackAlert::escape("Pejl: {$game->slug} {$reference} skickas inte. {$forward->last_error}"));
        } elseif ($ready) {
            SendPejlForward::dispatch($forward->id)->afterCommit();
        }

        return $forward;
    }

    private function kind(array $event): ?string
    {
        $type = $event['type'] ?? null;

        return match (true) {
            in_array($type, self::SALE_TYPES, true) => 'sale',
            is_string($type) && RefundRule::isRevocation($type, $event) => 'refund',
            $type === 'REFUND_REVERSED' => 'refund_reversed',
            default => null,
        };
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function saleAttributes(array $event): array
    {
        $currency = is_string($event['currency'] ?? null) && $event['currency'] !== '' ? $event['currency'] : null;
        $sek = $currency === 'SEK';
        $missing = array_values(array_filter([
            is_numeric($event['price_in_purchased_currency'] ?? null) ? null : 'price_in_purchased_currency',
            $currency ? null : 'currency',
            is_numeric($event['tax_percentage'] ?? null) ? null : 'tax_percentage',
            is_numeric($event['commission_percentage'] ?? null) ? null : 'commission_percentage',
            ! $currency || $sek || is_numeric($event['price'] ?? null) ? null : 'price',
        ]));

        $price = $sek ? ($event['price_in_purchased_currency'] ?? null) : ($event['price'] ?? null);
        $attributes = [
            'amount' => is_numeric($price) ? abs((float) $price) : null,
            'currency' => $currency ? ($sek ? 'SEK' : 'USD') : null,
            'tax_percentage' => is_numeric($event['tax_percentage'] ?? null) ? (float) $event['tax_percentage'] : null,
            'commission_percentage' => is_numeric($event['commission_percentage'] ?? null) ? (float) $event['commission_percentage'] : null,
        ];

        if ($missing) {
            return [['status' => 'failed', 'last_error' => 'Saknar fält från RevenueCat: '.implode(', ', $missing)] + $attributes, false];
        }

        foreach (['tax_percentage', 'commission_percentage'] as $field) {
            if ($attributes[$field] < 0 || $attributes[$field] >= 1) {
                return [['status' => 'failed', 'last_error' => "Orimlig {$field} från RevenueCat: {$attributes[$field]}"] + $attributes, false];
            }
        }

        return [['status' => 'pending'] + $attributes, true];
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function refundAttributes(Game $game, string $store, string $transactionId, array $event): array
    {
        $sale = $this->forwards($game, $store, $transactionId, 'sale')->first();
        if (! $sale) {
            return [['status' => 'skipped', 'last_error' => 'Köpet gjordes före vidarebefordringen'], false];
        }

        $attributes = $this->purchaseTerms($sale);
        $price = $this->priceInSaleCurrency($sale, $event);
        if ($price === false) {
            return [['status' => 'failed', 'last_error' => "Återbetalningens valuta ({$this->describeCurrency($event)}) stämmer inte med köpets ({$sale->currency})"] + $attributes, false];
        }

        $attributes['amount'] = $price ?? ($sale->amount === null ? null : (float) $sale->amount);
        if ($attributes['amount'] !== null && $sale->amount !== null && $attributes['amount'] > (float) $sale->amount) {
            return [['status' => 'failed', 'last_error' => "Återbetalningen ({$attributes['amount']} {$sale->currency}) är större än köpet ({$sale->amount} {$sale->currency})"] + $attributes, false];
        }

        return [['status' => 'pending'] + $attributes, $sale->status === 'sent'];
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function reversalAttributes(Game $game, string $store, string $transactionId): array
    {
        $sale = $this->forwards($game, $store, $transactionId, 'sale')->first();
        if (! $sale) {
            return [['status' => 'skipped', 'last_error' => 'Köpet gjordes före vidarebefordringen'], false];
        }

        // Varje återkallelse motverkar en skickad återbetalning, i ordning.
        $countered = $this->forwards($game, $store, $transactionId, 'refund_reversed')->where('status', '!=', 'skipped')->count();
        $refund = $this->forwards($game, $store, $transactionId, 'refund')->where('status', 'sent')->orderBy('id')->skip($countered)->first();

        $attributes = $this->purchaseTerms($sale);
        if (! $refund) {
            return [['status' => 'pending'] + $attributes, false];
        }

        return [['status' => 'pending', 'amount' => $refund->amount] + $attributes, true];
    }

    // Köpets valuta, procentsatser och kurs, så att återbetalningen tar ut köpet exakt.
    private function purchaseTerms(PejlForward $sale): array
    {
        return [
            'amount' => null,
            'currency' => $sale->currency,
            'tax_percentage' => $sale->tax_percentage,
            'commission_percentage' => $sale->commission_percentage,
            'fx_rate' => $sale->fx_rate,
            'fx_date' => $sale->fx_date,
        ];
    }

    // Händelsens pris i köpets valuta (samma valutaregel som köpet); null om händelsen saknar
    // pris, false om den har ett pris i en valuta som inte går ihop med köpets.
    private function priceInSaleCurrency(PejlForward $sale, array $event): float|false|null
    {
        $ppc = $event['price_in_purchased_currency'] ?? null;
        $usd = $event['price'] ?? null;
        if (! is_numeric($ppc) && ! is_numeric($usd)) {
            return null;
        }

        $currency = $event['currency'] ?? null;

        return match (true) {
            $sale->currency === 'SEK' && $currency === 'SEK' && is_numeric($ppc) => abs((float) $ppc),
            $sale->currency === 'USD' && is_string($currency) && $currency !== '' && $currency !== 'SEK' && is_numeric($usd) => abs((float) $usd),
            default => false,
        };
    }

    private function describeCurrency(array $event): string
    {
        return is_string($event['currency'] ?? null) ? $event['currency'] : 'saknas';
    }

    private function forwards(Game $game, string $store, string $transactionId, string $kind)
    {
        return PejlForward::where('game_id', $game->id)
            ->where('store', $store)
            ->where('transaction_id', $transactionId)
            ->where('kind', $kind);
    }

    private function occurredAt(string $kind, array $event): ?Carbon
    {
        $ms = $event[$kind === 'sale' ? 'purchased_at_ms' : 'event_timestamp_ms'] ?? null;

        return is_numeric($ms) ? Carbon::createFromTimestampMs((int) $ms, config('pejl.timezone'))->utc() : null;
    }

    private function description(Game $game, string $store, array $event): string
    {
        $productId = is_string($event['product_id'] ?? null) ? $event['product_id'] : null;
        $name = $productId
            ? (Product::where('game_id', $game->id)->where('product_id', $productId)->value('display_name') ?? $productId)
            : 'Okänd produkt';
        $country = is_string($event['country_code'] ?? null) && $event['country_code'] !== '' ? $event['country_code'] : null;

        return implode(' · ', array_filter([$name, self::STORES[$store], $country]));
    }

    private function requiredString(array $event, string $field): string
    {
        $value = $event[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("RevenueCat-händelsen saknar {$field}");
        }

        return $value;
    }
}
