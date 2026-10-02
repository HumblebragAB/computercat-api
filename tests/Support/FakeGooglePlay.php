<?php

namespace Tests\Support;

use App\Services\Glosis\GooglePurchaseVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Ett påhittat Google-tjänstekonto (egen RSA-nyckel, genererad i minnet) och
 * Http::fake för Googles OAuth och purchases.productsv2.
 */
final class FakeGooglePlay
{
    public const EMAIL = 'glosis-test@glosis-test.iam.gserviceaccount.com';

    public const ACCESS_TOKEN = 'ya29.test-access-token';

    public const ORDER_ID = 'GPA.3301-1234-5678-90123';

    public const PURCHASE_TOKEN = 'abcdefghijklmnop.AO-J1OxTestPurchaseToken_123';

    private static ?\OpenSSLAsymmetricKey $key = null;

    /** @var list<Request> */
    public array $tokenRequests = [];

    /** @var list<Request> */
    public array $purchaseRequests = [];

    /** @var \Closure(Request): mixed */
    public \Closure $purchaseAnswer;

    /** @var \Closure(Request): mixed */
    public \Closure $tokenAnswer;

    public function __construct()
    {
        $this->tokenAnswer = fn () => Http::response(['access_token' => self::ACCESS_TOKEN, 'expires_in' => 3599, 'token_type' => 'Bearer']);
        $this->purchaseAnswer = fn () => Http::response(self::purchase());
    }

    public static function key(): \OpenSSLAsymmetricKey
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public static function publicKeyPem(): string
    {
        return openssl_pkey_get_details(self::key())['key'];
    }

    public static function serviceAccountJson(): string
    {
        openssl_pkey_export(self::key(), $pem);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'glosis-test',
            'private_key_id' => 'testkeyid123',
            'private_key' => $pem,
            'client_email' => self::EMAIL,
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    /** @return array{client_email: string, private_key: string, private_key_id: string|null} */
    public static function account(): array
    {
        $data = json_decode(self::serviceAccountJson(), true);

        return ['client_email' => $data['client_email'], 'private_key' => $data['private_key'], 'private_key_id' => $data['private_key_id']];
    }

    /**
     * En ProductPurchaseV2 enligt
     * https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.productsv2
     *
     * @param  array<string, mixed>  $overrides  toppnivåfält (null tar bort fältet)
     * @param  array<string, mixed>  $offer  productOfferDetails-fält
     */
    public static function purchase(string $productId = 'glosis_guld_2026_27', array $overrides = [], array $offer = []): array
    {
        $data = array_merge([
            'kind' => 'androidpublisher#productPurchaseV2',
            'productLineItem' => [[
                'productId' => $productId,
                'productOfferDetails' => array_merge([
                    'purchaseOptionId' => 'legacy-base',
                    'quantity' => 1,
                    'refundableQuantity' => 1,
                    'consumptionState' => 'CONSUMPTION_STATE_YET_TO_BE_CONSUMED',
                ], $offer),
            ]],
            'purchaseStateContext' => ['purchaseState' => 'PURCHASED'],
            'orderId' => self::ORDER_ID,
            'regionCode' => 'SE',
            'purchaseCompletionTime' => '2026-09-01T10:00:00.123Z',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
        ], $overrides);

        return array_filter($data, fn ($v) => $v !== null);
    }

    public function fake(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => function (Request $request) {
                $this->tokenRequests[] = $request;

                return ($this->tokenAnswer)($request);
            },
            'androidpublisher.googleapis.com/*' => function (Request $request) {
                $this->purchaseRequests[] = $request;

                return ($this->purchaseAnswer)($request);
            },
        ]);
    }

    public static function purchaseUrl(string $package = 'se.computercat.glosis', string $token = self::PURCHASE_TOKEN): string
    {
        return GooglePurchaseVerifier::API_BASE.'/applications/'.$package.'/purchases/productsv2/tokens/'.$token;
    }
}
