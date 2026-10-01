<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use Illuminate\Console\Command;

/**
 * Rättar köp som återbetalats men ligger kvar som ägda.
 *
 * Fram till rättningen av webhooken satte en CANCELLATION status 'pending'
 * (räknas som ägt) i stället för 'refunded'. RevenueCat skickar återbetalningar
 * som CANCELLATION med cancel_reason CUSTOMER_SUPPORT.
 *
 * Bara köp vars sparade receipt_data är just en sådan händelse ändras. Den
 * gamla webhooken skrev aldrig receipt_data vid CANCELLATION, så webhook-köp
 * som fastnat i 'pending' visas som misstänkta men ändras inte: skälet till
 * avbrottet finns inte sparat och måste stämmas av mot RevenueCat.
 */
class ReconcileRevenueCatRefunds extends Command
{
    protected $signature = 'revenuecat:reconcile-refunds
        {--dry-run : Visa bara vad som skulle ändras (standard)}
        {--apply : Markera träffarna som refunded}';

    protected $description = 'Markerar köp som RevenueCat återbetalat (CANCELLATION/CUSTOMER_SUPPORT) som refunded';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Välj antingen --dry-run eller --apply, inte båda.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $toRefund = [];
        $suspects = [];

        Purchase::where('status', '!=', 'refunded')
            ->whereNotNull('receipt_data')
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (Purchase $purchase) use (&$toRefund, &$suspects) {
                $event = json_decode((string) $purchase->receipt_data, true);
                if (! is_array($event)) {
                    return;
                }

                $type = $event['type'] ?? null;

                if ($type === 'CANCELLATION' && ($event['cancel_reason'] ?? null) === 'CUSTOMER_SUPPORT') {
                    $toRefund[] = $purchase;

                    return;
                }

                // Webhook-skapat engångsköp (expiration_at_ms = null) som står
                // på 'pending': bara den gamla CANCELLATION-grenen sätter det.
                $isOneTimeGrant = in_array($type, ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE'], true)
                    && array_key_exists('expiration_at_ms', $event)
                    && $event['expiration_at_ms'] === null;

                if ($purchase->status === 'pending' && $isOneTimeGrant) {
                    $suspects[] = $purchase;
                }
            });

        $this->info('Att markera som refunded: '.count($toRefund));
        $this->info('Misstänkta (ändras inte): '.count($suspects));

        $rows = fn (array $purchases) => array_map(fn (Purchase $p) => [
            $p->id, $p->game_id, $p->user_id, $p->product_id, $p->transaction_id, $p->status,
        ], $purchases);
        $headers = ['id', 'game_id', 'user_id', 'product_id', 'transaction_id', 'status'];

        if ($toRefund) {
            $this->line('Att markera som refunded:');
            $this->table($headers, $rows($toRefund));
        }

        if ($suspects) {
            $this->line('Misstänkta: webhook-engångsköp som står på pending. Stäm av mot RevenueCat innan något ändras.');
            $this->table($headers, $rows($suspects));
        }

        if (! $apply) {
            $this->warn('Torrkörning: inget ändrat. Kör med --apply för att markera träffarna.');

            return self::SUCCESS;
        }

        $ids = array_map(fn (Purchase $p) => $p->id, $toRefund);
        $updated = $ids === []
            ? 0
            : Purchase::whereIn('id', $ids)->where('status', '!=', 'refunded')->update(['status' => 'refunded']);

        $this->info("Uppdaterade {$updated} köp till refunded.");

        return self::SUCCESS;
    }
}
