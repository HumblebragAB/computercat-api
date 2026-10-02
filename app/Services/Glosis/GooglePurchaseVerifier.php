<?php

namespace App\Services\Glosis;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSSLAsymmetricKey;
use Throwable;

/**
 * Verifierar ett Google Play-engångsköp hos Google Play Developer API.
 *
 * Anropet: purchases.productsv2.getproductpurchasev2, som Google anvisar för
 * att hämta ett engångsköps aktuella status
 * (https://developer.android.com/google/play/billing/lifecycle/one-time):
 *   GET https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{packageName}/purchases/productsv2/tokens/{token}
 * Svaret är en ProductPurchaseV2:
 * https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.productsv2
 *
 *  - purchaseStateContext.purchaseState: PURCHASED | CANCELLED | PENDING.
 *    Bara PURCHASED ger Guld.
 *  - productLineItem[].productId och .productOfferDetails.consumptionState
 *    (CONSUMPTION_STATE_CONSUMED ger inget: Guld förbrukas aldrig) och
 *    .refundableQuantity ("quantity that hasn't been refunded", 0 = helt återbetalt).
 *  - testPurchaseContext: "only be set for test purchases" → Sandbox.
 *  - orderId (GPA.…): RevenueCat skickar samma id som transaction_id, så det
 *    blir originalTransactionId och spärrlistan träffar. "May not be set if
 *    there is no order associated with the purchase"; då används en hash av
 *    purchaseToken (sådana köp kan inte spärras via RevenueCat, loggas).
 *  - purchaseCompletionTime (RFC 3339) → köptidpunkt.
 *
 * Inloggning: OAuth 2.0 för tjänstekonton (JWT bearer), scope androidpublisher:
 * https://developers.google.com/identity/protocols/oauth2/service-account
 * Åtkomsttoken cachas krypterad till expires_in − 60 s.
 *
 * Tjänstekontot behöver i Play Console (Användare och behörigheter) rätten
 * "View financial data, orders, and cancellation survey responses"
 * (Visa ekonomisk data …) för appen; Google anger den tillsammans med
 * "Manage orders and subscriptions" för Billing-API:erna:
 * https://developers.google.com/android-publisher/getting_started
 */
final class GooglePurchaseVerifier
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const API_BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3';

    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public const REQUIRED_PERMISSION = 'View financial data, orders, and cancellation survey responses';

    /** purchaseToken går in i adressen. Googles token är bokstäver, siffror, punkt, bindestreck och understreck. */
    private const TOKEN_RE = '/^[A-Za-z0-9._-]{1,1024}\z/';

    private const PRODUCT_RE = '/^[a-z0-9][a-z0-9._]{0,138}\z/';

    private const LOG_BODY_BYTES = 4096;

    /**
     * @param  array{client_email: string, private_key: string, private_key_id: string|null}  $account
     *
     * @throws InvalidProofException köpet finns inte eller hör inte till appen/produkten
     * @throws StoreUnavailableException Google gick inte att fråga
     */
    public function verify(array $account, string $packageName, string $productId, string $purchaseToken): VerifiedTransaction
    {
        if (preg_match(self::TOKEN_RE, $purchaseToken) !== 1) {
            throw new InvalidProofException('purchaseToken har fel form');
        }
        if (preg_match(self::PRODUCT_RE, $productId) !== 1) {
            throw new InvalidProofException('productId har fel form');
        }

        $response = $this->fetchPurchase($account, $packageName, $purchaseToken);

        return $this->toTransaction($response, $productId, $purchaseToken);
    }

    /** @param array{client_email: string, private_key: string, private_key_id: string|null} $account */
    private function fetchPurchase(array $account, string $packageName, string $purchaseToken): Response
    {
        $url = self::API_BASE.'/applications/'.rawurlencode($packageName).'/purchases/productsv2/tokens/'.rawurlencode($purchaseToken);

        try {
            $response = Http::withToken($this->accessToken($account))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            // cURL-felet innehåller adressen, och adressen innehåller köpets token.
            Log::warning('Glosis Google Play: purchases-anropet nådde inte fram', ['package' => $packageName, 'error' => str_replace([rawurlencode($purchaseToken), $purchaseToken], '[token]', $e->getMessage())]);

            throw new StoreUnavailableException('Google Play gick inte att nå');
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $context = ['package' => $packageName, 'status' => $status, 'body' => self::truncate($response->body())];

        if ($status === 401 || $status === 403) {
            // 401: token ogiltig trots cache (återkallad nyckel?). Hämta ny nästa gång.
            Cache::forget(self::cacheKey($account));
            Log::error('Glosis Google Play: tjänstekontot '.$account['client_email'].' saknar behörighet. Ge det "'
                .self::REQUIRED_PERMISSION.'" för appen i Play Console (Användare och behörigheter), och kontrollera att Google Play Android Developer API är aktiverat i Cloud-projektet.', $context);

            throw new StoreUnavailableException("Google Play svarade {$status}");
        }

        if (in_array($status, [400, 404, 410], true)) {
            Log::warning('Glosis Google Play: köpet finns inte', $context);

            throw new InvalidProofException("Google Play svarade {$status} för köpet");
        }

        Log::warning('Glosis Google Play: oväntat svar', $context);

        throw new StoreUnavailableException("Google Play svarade {$status}");
    }

    private function toTransaction(Response $response, string $productId, string $purchaseToken): VerifiedTransaction
    {
        $data = $response->json();
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidProofException('Svaret från Google Play är inte ett JSON-objekt');
        }

        $item = null;
        $items = $data['productLineItem'] ?? null;
        if (is_array($items) && array_is_list($items)) {
            foreach ($items as $candidate) {
                if (is_array($candidate) && ($candidate['productId'] ?? null) === $productId) {
                    $item = $candidate;
                    break;
                }
            }
        }
        if ($item === null) {
            throw new InvalidProofException('Köpet gäller inte produkten i beviset');
        }

        $state = $data['purchaseStateContext']['purchaseState'] ?? null;
        if (! in_array($state, ['PURCHASED', 'CANCELLED', 'PENDING'], true)) {
            throw new InvalidProofException('Okänd purchaseState');
        }

        $offer = is_array($item['productOfferDetails'] ?? null) ? $item['productOfferDetails'] : [];
        $consumed = ($offer['consumptionState'] ?? null) === 'CONSUMPTION_STATE_CONSUMED';
        $refunded = ($offer['refundableQuantity'] ?? null) === 0;

        $orderId = $data['orderId'] ?? null;
        if (is_string($orderId) && $orderId !== '') {
            $original = $orderId;
        } else {
            $original = 'gpt:'.hash('sha256', $purchaseToken);
            Log::info('Glosis Google Play: köpet saknar orderId, spärras inte via RevenueCat', ['tx' => substr(hash('sha256', $original), 0, 16)]);
        }

        if (($data['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_PENDING' && $state === 'PURCHASED') {
            // RevenueCat kvitterar köpet; okvitterat i tre dagar återbetalar Google.
            Log::info('Glosis Google Play: köpet är inte kvitterat än', ['tx' => substr(hash('sha256', $original), 0, 16)]);
        }

        $test = $data['testPurchaseContext'] ?? null;

        return new VerifiedTransaction(
            originalTransactionId: $original,
            transactionId: $original,
            productId: $productId,
            environment: is_array($test) ? 'Sandbox' : 'Production',
            revocationDate: null,
            storeRevoked: $state !== 'PURCHASED' || $consumed || $refunded,
            purchaseDate: self::timestampMs($data['purchaseCompletionTime'] ?? null),
        );
    }

    /** @param array{client_email: string, private_key: string, private_key_id: string|null} $account */
    private function accessToken(array $account): string
    {
        $key = self::cacheKey($account);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($key);
            }
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->assertion($account),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Glosis Google Play: tokenutbytet nådde inte fram', ['error' => $e->getMessage()]);

            throw new StoreUnavailableException('Googles OAuth gick inte att nå');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Googles felkropp ({"error": "invalid_grant", ...}) innehåller inga hemligheter.
            Log::error('Glosis Google Play: tjänstekontot '.$account['client_email'].' fick ingen åtkomsttoken. Är nyckeln borttagen eller tjänstekontot avstängt?', [
                'status' => $response->status(),
                'body' => self::truncate($response->body()),
            ]);

            throw new StoreUnavailableException('Ingen åtkomsttoken från Google');
        }

        $ttl = (is_int($expiresIn) ? $expiresIn : 0) - 60;
        if ($ttl > 0) {
            Cache::put($key, Crypt::encryptString($token), $ttl);
        }

        return $token;
    }

    /** @param array{client_email: string, private_key: string, private_key_id: string|null} $account */
    private function assertion(array $account): string
    {
        $privateKey = openssl_pkey_get_private($account['private_key']);
        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            Log::error('Glosis Google Play: tjänstekontots privata nyckel går inte att läsa');

            throw new StoreUnavailableException('Privata nyckeln går inte att läsa');
        }

        $now = now()->getTimestamp();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($account['private_key_id'] !== null) {
            $header['kid'] = $account['private_key_id'];
        }
        $claims = [
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $input = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR)).'.'.self::base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new StoreUnavailableException('JWT gick inte att signera');
        }

        return $input.'.'.self::base64Url($signature);
    }

    /** @param array{client_email: string, private_key: string, private_key_id: string|null} $account */
    public static function cacheKey(array $account): string
    {
        return 'glosis:google-play:access-token:'.hash('sha256', $account['client_email'].'|'.($account['private_key_id'] ?? hash('sha256', $account['private_key'])));
    }

    private static function timestampMs(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return (int) CarbonImmutable::parse($value)->getTimestampMs();
        } catch (Throwable) {
            return null;
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function truncate(string $body): string
    {
        return strlen($body) > self::LOG_BODY_BYTES ? substr($body, 0, self::LOG_BODY_BYTES).'…' : $body;
    }
}
