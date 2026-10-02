<?php

namespace Tests\Feature\Glosis;

use App\Services\Glosis\GooglePurchaseVerifier;
use App\Services\Glosis\InvalidProofException;
use App\Services\Glosis\StoreUnavailableException;
use App\Services\Glosis\VerifiedTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGooglePlay;
use Tests\TestCase;

class GooglePurchaseVerifierTest extends TestCase
{
    private const PRODUCT = 'glosis_guld_2026_27';

    private FakeGooglePlay $google;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->google = new FakeGooglePlay;
        $this->google->fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function verify(string $product = self::PRODUCT, string $token = FakeGooglePlay::PURCHASE_TOKEN, string $package = 'se.computercat.glosis'): VerifiedTransaction
    {
        return (new GooglePurchaseVerifier)->verify(FakeGooglePlay::account(), $package, $product, $token);
    }

    /** @return list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logs) {
            $logs[] = $m;
        });

        return function () use (&$logs) {
            return $logs;
        };
    }

    public function test_valid_purchase(): void
    {
        $tx = $this->verify();

        $this->assertSame(FakeGooglePlay::ORDER_ID, $tx->originalTransactionId);
        $this->assertSame(FakeGooglePlay::ORDER_ID, $tx->transactionId);
        $this->assertSame(self::PRODUCT, $tx->productId);
        $this->assertSame('Production', $tx->environment);
        $this->assertFalse($tx->isRevoked());
        $this->assertSame(1_788_256_800_123, $tx->purchaseDate);

        // Anropet följer purchases.productsv2.getproductpurchasev2.
        $request = $this->google->purchaseRequests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(FakeGooglePlay::purchaseUrl(), $request->url());
        $this->assertSame('Bearer '.FakeGooglePlay::ACCESS_TOKEN, $request->header('Authorization')[0]);
    }

    public function test_token_request_is_a_signed_jwt_bearer_assertion(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');
        $this->verify();

        $request = $this->google->tokenRequests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);

        [$h, $p, $s] = explode('.', $request['assertion']);
        $dec = fn ($v) => base64_decode(strtr($v, '-_', '+/'));
        $header = json_decode($dec($h), true);
        $claims = json_decode($dec($p), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'testkeyid123'], $header);
        $this->assertSame(FakeGooglePlay::EMAIL, $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/androidpublisher', $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(now()->getTimestamp(), $claims['iat']);
        $this->assertSame(now()->getTimestamp() + 3600, $claims['exp']);
        $this->assertSame(1, openssl_verify("{$h}.{$p}", $dec($s), FakeGooglePlay::publicKeyPem(), OPENSSL_ALGO_SHA256));
    }

    public function test_access_token_is_cached_encrypted_until_sixty_seconds_before_expiry(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');
        $this->verify();
        $this->verify();
        $this->assertCount(1, $this->google->tokenRequests);
        $this->assertCount(2, $this->google->purchaseRequests);

        $cached = Cache::get(GooglePurchaseVerifier::cacheKey(FakeGooglePlay::account()));
        $this->assertIsString($cached);
        $this->assertStringNotContainsString(FakeGooglePlay::ACCESS_TOKEN, $cached);

        // expires_in 3599 − 60 = 3539 s.
        Carbon::setTestNow('2026-10-02 12:58:58');
        $this->verify();
        $this->assertCount(1, $this->google->tokenRequests);

        Carbon::setTestNow('2026-10-02 12:59:00');
        $this->verify();
        $this->assertCount(2, $this->google->tokenRequests);
    }

    public function test_test_purchase_is_sandbox(): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase(overrides: ['testPurchaseContext' => ['fopType' => 'TEST']]));

        $this->assertSame('Sandbox', $this->verify()->environment);
    }

    public static function revokedPurchases(): array
    {
        return [
            'makulerat' => [['purchaseStateContext' => ['purchaseState' => 'CANCELLED']], []],
            'väntande betalning' => [['purchaseStateContext' => ['purchaseState' => 'PENDING'], 'purchaseCompletionTime' => null], []],
            'förbrukat' => [[], ['consumptionState' => 'CONSUMPTION_STATE_CONSUMED']],
            'helt återbetalt' => [[], ['refundableQuantity' => 0]],
        ];
    }

    #[DataProvider('revokedPurchases')]
    public function test_purchase_that_gives_nothing_is_revoked(array $overrides, array $offer): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase(overrides: $overrides, offer: $offer));

        $tx = $this->verify();
        $this->assertTrue($tx->storeRevoked);
        $this->assertTrue($tx->isRevoked());
    }

    public function test_unacknowledged_purchase_is_accepted(): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase(overrides: ['acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING']));

        $this->assertFalse($this->verify()->isRevoked());
    }

    public function test_purchase_for_another_product_is_invalid(): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase('glosis_guld_2025_26'));

        $this->expectException(InvalidProofException::class);
        $this->verify();
    }

    public function test_missing_order_id_falls_back_to_token_hash(): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase(overrides: ['orderId' => null]));

        $this->assertSame('gpt:'.hash('sha256', FakeGooglePlay::PURCHASE_TOKEN), $this->verify()->originalTransactionId);
    }

    public static function malformedResponses(): array
    {
        return [
            'okänt tillstånd' => [FakeGooglePlay::purchase(overrides: ['purchaseStateContext' => ['purchaseState' => 'PURCHASE_STATE_UNSPECIFIED']])],
            'inget tillstånd' => [FakeGooglePlay::purchase(overrides: ['purchaseStateContext' => null])],
            'inga rader' => [FakeGooglePlay::purchase(overrides: ['productLineItem' => []])],
            'lista' => [[1, 2]],
        ];
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_response_is_invalid(array $body): void
    {
        $this->google->purchaseAnswer = fn () => Http::response($body);

        $this->expectException(InvalidProofException::class);
        $this->verify();
    }

    public static function unknownTokenStatuses(): array
    {
        return ['400' => [400], '404' => [404], '410' => [410]];
    }

    /** Okänd eller utgången token, eller köp i ett annat paket. */
    #[DataProvider('unknownTokenStatuses')]
    public function test_unknown_token_or_wrong_package_is_invalid(int $status): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(['error' => ['code' => $status, 'message' => 'The purchase token was not found.']], $status);

        $this->expectException(InvalidProofException::class);
        $this->verify(package: 'se.computercat.annan');
    }

    public static function malformedProofs(): array
    {
        return [
            'token med snedstreck' => [self::PRODUCT, '../../tokens/x'],
            'token med frågetecken' => [self::PRODUCT, 'abc?x=1'],
            'för lång token' => [self::PRODUCT, str_repeat('a', 1025)],
            'produkt med versaler' => ['GLOSIS', FakeGooglePlay::PURCHASE_TOKEN],
            'produkt med snedstreck' => ['glosis/x', FakeGooglePlay::PURCHASE_TOKEN],
        ];
    }

    #[DataProvider('malformedProofs')]
    public function test_malformed_proof_never_reaches_google(string $product, string $token): void
    {
        try {
            $this->verify($product, $token);
            $this->fail('Ingen InvalidProofException');
        } catch (InvalidProofException) {
            $this->assertSame([], $this->google->tokenRequests);
            $this->assertSame([], $this->google->purchaseRequests);
        }
    }

    public function test_google_403_is_unavailable_and_names_the_permission(): void
    {
        $logs = $this->captureLogs();
        $this->google->purchaseAnswer = fn () => Http::response(['error' => ['code' => 403, 'message' => 'The current user has insufficient permissions to perform the requested operation.']], 403);

        try {
            $this->verify();
            $this->fail('Ingen StoreUnavailableException');
        } catch (StoreUnavailableException) {
        }

        $error = collect($logs())->first(fn (MessageLogged $m) => $m->level === 'error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('View financial data, orders, and cancellation survey responses', $error->message);
        $this->assertStringContainsString(FakeGooglePlay::EMAIL, $error->message);
        $this->assertStringContainsString('insufficient permissions', $error->context['body']);
        $this->assertStringNotContainsString(FakeGooglePlay::PURCHASE_TOKEN, json_encode($logs()));
        $this->assertStringNotContainsString(FakeGooglePlay::ACCESS_TOKEN, json_encode($logs()));
    }

    public function test_google_401_drops_the_cached_token(): void
    {
        $this->google->purchaseAnswer = fn () => Http::response(['error' => ['code' => 401]], 401);

        try {
            $this->verify();
        } catch (StoreUnavailableException) {
        }
        $this->assertNull(Cache::get(GooglePurchaseVerifier::cacheKey(FakeGooglePlay::account())));

        $this->google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase());
        $this->verify();
        $this->assertCount(2, $this->google->tokenRequests);
    }

    public static function unavailableAnswers(): array
    {
        return [
            '500' => [fn () => Http::response('', 500)],
            '503' => [fn () => Http::response(['error' => ['code' => 503]], 503)],
            '429' => [fn () => Http::response(['error' => ['code' => 429]], 429)],
            'nätverk' => [fn () => throw new ConnectionException('timeout')],
        ];
    }

    #[DataProvider('unavailableAnswers')]
    public function test_google_outage_is_unavailable(\Closure $answer): void
    {
        $this->google->purchaseAnswer = $answer;

        $this->expectException(StoreUnavailableException::class);
        $this->verify();
    }

    public function test_network_error_log_never_contains_the_purchase_token(): void
    {
        $logs = $this->captureLogs();
        $this->google->purchaseAnswer = fn () => throw new ConnectionException('cURL error 28: timed out for '.FakeGooglePlay::purchaseUrl());

        try {
            $this->verify();
        } catch (StoreUnavailableException) {
        }
        $this->assertNotEmpty($logs());
        $this->assertStringNotContainsString(FakeGooglePlay::PURCHASE_TOKEN, json_encode(collect($logs())->map(fn ($m) => [$m->message, $m->context])));
    }

    public function test_rejected_service_account_is_unavailable_and_not_cached(): void
    {
        $logs = $this->captureLogs();
        $this->google->tokenAnswer = fn () => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400);

        try {
            $this->verify();
            $this->fail('Ingen StoreUnavailableException');
        } catch (StoreUnavailableException) {
        }
        $this->assertSame([], $this->google->purchaseRequests);
        $this->assertNull(Cache::get(GooglePurchaseVerifier::cacheKey(FakeGooglePlay::account())));
        $this->assertTrue(collect($logs())->contains(fn (MessageLogged $m) => $m->level === 'error' && str_contains($m->context['body'] ?? '', 'invalid_grant')));
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($logs()));
    }

    public function test_token_endpoint_unreachable_is_unavailable(): void
    {
        $this->google->tokenAnswer = fn () => throw new ConnectionException('dns');

        $this->expectException(StoreUnavailableException::class);
        $this->verify();
    }

    public function test_unreadable_private_key_is_unavailable(): void
    {
        $this->expectException(StoreUnavailableException::class);
        (new GooglePurchaseVerifier)->verify(['client_email' => FakeGooglePlay::EMAIL, 'private_key' => "-----BEGIN PRIVATE KEY-----\nskräp\n-----END PRIVATE KEY-----\n", 'private_key_id' => null], 'se.computercat.glosis', self::PRODUCT, FakeGooglePlay::PURCHASE_TOKEN);
    }
}
