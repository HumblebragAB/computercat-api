<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Handles webhook events from RevenueCat.
 *
 * RevenueCat sends events when purchases happen, renew, cancel, or refund.
 * We use these events to keep our Purchase table in sync with the source of truth.
 *
 * Signature verification:
 * RevenueCat sends the webhook secret as a Bearer token in the Authorization header.
 * We compare it to the encrypted secret stored in games.settings.revenuecat.webhook_secret.
 *
 * Docs: https://www.revenuecat.com/docs/webhooks
 */
class RevenueCatWebhookController extends Controller
{
    public function handle(Request $request, Game $game): JsonResponse
    {
        // Verify Authorization header — accept both "Bearer <secret>" and raw "<secret>"
        // since RevenueCat sends whatever you put in the dashboard verbatim.
        $authHeader = $request->header('Authorization');
        if (! $authHeader) {
            return response()->json(['message' => 'Missing authorization.'], 401);
        }

        $providedToken = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : $authHeader;

        // Get the configured webhook secret for this game
        $encryptedSecret = $game->settings['revenuecat']['webhook_secret'] ?? null;
        if (! $encryptedSecret) {
            Log::warning("RevenueCat webhook received for {$game->slug} but no secret configured");

            return response()->json(['message' => 'Webhook not configured for this game.'], 501);
        }

        try {
            $expectedSecret = Crypt::decryptString($encryptedSecret);
        } catch (\Throwable $e) {
            Log::error("Failed to decrypt RevenueCat webhook secret for {$game->slug}", ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Configuration error.'], 500);
        }

        if (! hash_equals($expectedSecret, $providedToken)) {
            Log::warning("Invalid RevenueCat webhook signature for {$game->slug}");

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Parse event
        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;

        if (! $event) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        $type = $event['type'] ?? null;
        if (! $type) {
            return response()->json(['message' => 'Missing event type.'], 422);
        }

        // TEST events from the RevenueCat dashboard don't have purchase fields —
        // just acknowledge them so the integration test passes.
        if ($type === 'TEST') {
            Log::info("RevenueCat test webhook received for {$game->slug}");

            return response()->json(['message' => 'Test webhook received.']);
        }

        if ($type === 'TRANSFER') {
            $this->processTransfer($game, $event);

            return response()->json(['message' => 'OK']);
        }

        $appUserId = $event['app_user_id'] ?? null;
        $productId = $event['product_id'] ?? null;
        $transactionId = $event['transaction_id'] ?? null;
        $store = $this->mapStore($event['store'] ?? null);

        // RevenueCat har ingen REFUND-händelse. Återbetalningar kommer som
        // CANCELLATION med cancel_reason CUSTOMER_SUPPORT, och en återkallad
        // återbetalning som REFUND_REVERSED. Övriga typer (SUBSCRIBER_ALIAS,
        // BILLING_ISSUE m.fl.) kvitteras utan att något ändras.
        $grantEvents = ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'RENEWAL', 'UNCANCELLATION'];
        $statusEvents = ['CANCELLATION', 'EXPIRATION', 'REFUND_REVERSED'];

        if (! in_array($type, [...$grantEvents, ...$statusEvents], true)) {
            Log::info("RevenueCat event ignored: {$type}");

            return response()->json(['message' => 'Event acknowledged but not processed.']);
        }

        if (! $transactionId) {
            Log::warning("RevenueCat {$type} event missing transaction_id", ['game' => $game->slug, 'event_id' => $event['id'] ?? null]);

            return response()->json(['message' => 'Missing required event fields.'], 422);
        }

        // Statusändringar gäller köpet (transaction_id), inte vem som senast
        // sågs i RevenueCat. app_user_id kan vara ett anonymt id efter en
        // TRANSFER, och en återbetalning får aldrig tappas för att användaren
        // inte går att slå upp.
        if (in_array($type, $statusEvents, true)) {
            $this->processStatusEvent($type, $game, $transactionId, $event);

            return response()->json(['message' => 'OK']);
        }

        if (! $appUserId || ! $productId) {
            Log::warning("RevenueCat {$type} event missing required fields", ['game' => $game->slug, 'event_id' => $event['id'] ?? null]);

            return response()->json(['message' => 'Missing required event fields.'], 422);
        }

        // Resolve user from app_user_id (we set this to our numeric user ID in the client)
        $user = $this->resolveUser($appUserId);
        if (! $user) {
            Log::warning('RevenueCat webhook for unknown user', ['app_user_id' => $appUserId, 'type' => $type]);

            // Return 200 to prevent RC from retrying indefinitely
            return response()->json(['message' => 'User not found, ignoring.'], 200);
        }

        $this->processGrant($user, $game, $productId, $transactionId, $store, $event);

        return response()->json(['message' => 'OK']);
    }

    /**
     * INITIAL_PURCHASE, NON_RENEWING_PURCHASE, RENEWAL, UNCANCELLATION: ge åtkomst.
     *
     * Ett återbetalt köp återställs bara av REFUND_REVERSED. RevenueCat
     * levererar om händelser vid fel, och ett omlevererat köp-event får inte
     * låsa upp något som redan återbetalats.
     */
    private function processGrant(
        User $user,
        Game $game,
        string $productId,
        string $transactionId,
        string $store,
        array $event,
    ): void {
        $purchasedAt = isset($event['purchased_at_ms'])
            ? now()->createFromTimestampMs((int) $event['purchased_at_ms'])
            : now();

        $existing = Purchase::where('transaction_id', $transactionId)->first();
        if ($existing && $existing->status === 'refunded') {
            Log::warning('RevenueCat grant event for refunded purchase ignored', [
                'type' => $event['type'] ?? null,
                'purchase_id' => $existing->id,
            ]);

            return;
        }

        Purchase::updateOrCreate(
            [
                'transaction_id' => $transactionId,
            ],
            [
                'user_id' => $user->id,
                'game_id' => $game->id,
                'product_id' => $productId,
                'store' => $store,
                'receipt_data' => json_encode($event),
                'status' => 'verified',
                'purchased_at' => $purchasedAt,
            ]
        );
    }

    /**
     * CANCELLATION, EXPIRATION, REFUND_REVERSED: ändra status på ett befintligt köp.
     *
     * Avgränsas till spelet vars webhook-hemlighet anropet bar, så att ett
     * spels hemlighet aldrig kan ändra ett annat spels köp.
     */
    private function processStatusEvent(string $type, Game $game, string $transactionId, array $event): void
    {
        $query = Purchase::where('game_id', $game->id)->where('transaction_id', $transactionId);
        $reason = $event['cancel_reason'] ?? null;

        if ($type === 'REFUND_REVERSED') {
            $updated = (clone $query)->where('status', 'refunded')->update(['status' => 'verified']);
            Log::info('RevenueCat refund reversed', ['transaction_id' => $transactionId, 'updated' => $updated]);

            return;
        }

        // Engångsköp har expiration_at_ms = null (RevenueCats exempel för
        // NON_RENEWING_PURCHASE). För dem finns ingen period kvar att löpa ut,
        // så en CANCELLATION betyder att köpet är makulerat oavsett skäl.
        $isOneTime = ! isset($event['expiration_at_ms']);

        $revoke = $type === 'CANCELLATION'
            && ($reason === 'CUSTOMER_SUPPORT' || $isOneTime);

        if ($revoke) {
            $updated = (clone $query)->update(['status' => 'refunded']);
            Log::info('RevenueCat purchase revoked', [
                'transaction_id' => $transactionId,
                'cancel_reason' => $reason,
                'one_time' => $isOneTime,
                'updated' => $updated,
            ]);

            if ($updated === 0) {
                Log::warning('RevenueCat revocation matched no purchase', ['game' => $game->slug, 'transaction_id' => $transactionId]);
            }

            return;
        }

        // Prenumeration som sagts upp eller löpt ut utan återbetalning:
        // användaren hade åtkomst under perioden. Ett återbetalt köp får
        // inte bli 'pending' (ägt) igen den här vägen.
        (clone $query)->where('status', '!=', 'refunded')->update(['status' => 'pending']);
    }

    /**
     * TRANSFER: flytta köp mellan app user ids.
     *
     * Fält enligt RevenueCat: transferred_from och transferred_to är listor
     * av app user ids. Bara numeriska id:n som finns hos oss räknas; övriga
     * (t.ex. $RCAnonymousID:...) loggas och hoppas över.
     */
    private function processTransfer(Game $game, array $event): void
    {
        $from = $this->resolveUsers($event['transferred_from'] ?? []);
        $to = $this->resolveUsers($event['transferred_to'] ?? []);

        if ($to->count() !== 1) {
            Log::warning('RevenueCat TRANSFER without exactly one known destination user, ignored', [
                'game' => $game->slug,
                'event_id' => $event['id'] ?? null,
                'transferred_to' => $event['transferred_to'] ?? null,
            ]);

            return;
        }

        $target = $to->first();
        $sourceIds = $from->pluck('id')->reject(fn ($id) => $id === $target->id)->values();

        if ($sourceIds->isEmpty()) {
            Log::info('RevenueCat TRANSFER without known source users, nothing moved', [
                'game' => $game->slug,
                'event_id' => $event['id'] ?? null,
                'transferred_from' => $event['transferred_from'] ?? null,
            ]);

            return;
        }

        $moved = Purchase::where('game_id', $game->id)
            ->whereIn('user_id', $sourceIds)
            ->update(['user_id' => $target->id]);

        Log::info('RevenueCat TRANSFER applied', [
            'game' => $game->slug,
            'from_user_ids' => $sourceIds->all(),
            'to_user_id' => $target->id,
            'purchases_moved' => $moved,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveUsers(mixed $appUserIds): \Illuminate\Support\Collection
    {
        if (! is_array($appUserIds)) {
            return collect();
        }

        $users = collect();
        foreach ($appUserIds as $appUserId) {
            $user = is_string($appUserId) || is_int($appUserId) ? $this->resolveUser((string) $appUserId) : null;
            if ($user) {
                $users->put($user->id, $user);
            } else {
                Log::info('RevenueCat TRANSFER: unknown app_user_id ignored', ['app_user_id' => $appUserId]);
            }
        }

        return $users->values();
    }

    /**
     * Klienten loggar in i RevenueCat med vårt numeriska användar-id.
     * Allt annat (anonyma RC-id:n, "12abc") matchar ingen användare.
     */
    private function resolveUser(string $appUserId): ?User
    {
        if (! ctype_digit($appUserId)) {
            return null;
        }

        return User::find((int) $appUserId);
    }

    /**
     * Map RevenueCat store identifiers to our enum.
     */
    private function mapStore(?string $rcStore): string
    {
        return match ($rcStore) {
            'APP_STORE', 'MAC_APP_STORE' => 'apple',
            'PLAY_STORE' => 'google',
            'STRIPE', 'PROMOTIONAL' => 'web',
            default => 'web',
        };
    }
}
