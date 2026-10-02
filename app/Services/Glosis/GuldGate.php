<?php

namespace App\Services\Glosis;

use App\Models\Game;
use App\Models\RevokedStoreTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Rätten att använda en Guld-funktion (fotoskanning, studioröst). Glosis har
 * inga konton: appen skickar ett köpbevis.
 *
 *  iOS:     {"platform": "ios", "jws": "..."}, StoreKit 2, verifieras offline
 *           mot Apples rot (AppleTransactionVerifier).
 *  Android: {"platform": "android", "productId": "...", "purchaseToken": "..."},
 *           verifieras hos Google Play Developer API (GooglePurchaseVerifier).
 *
 * Två steg så att anroparen kan kontrollera sin egen indata emellan:
 * parse() (formen) och verify() (butiken, Guld-läsår, spärrlista).
 */
final class GuldGate
{
    public const FEATURE_SCAN = 'scan';

    public const FEATURE_TTS = 'tts';

    private const MESSAGES = [
        self::FEATURE_SCAN => [
            'invalid_proof' => 'Vi kunde inte kontrollera köpet av Guldstjärnan. Försök igen.',
            'platform_not_supported' => 'Att fota läxan fungerar inte på Android än. Skriv in orden själv så länge.',
            'guld_required' => 'Att fota läxan ingår i Guldstjärnan. Be en vuxen att titta på det.',
            'scan_unavailable' => 'Att fota läxan fungerar inte just nu. Skriv in orden själv så länge.',
        ],
        self::FEATURE_TTS => [
            'invalid_proof' => 'Vi kunde inte kontrollera köpet av Guldstjärnan. Försök igen.',
            'platform_not_supported' => 'Studiorösten fungerar inte på Android än. Appen använder telefonens röst så länge.',
            'guld_required' => 'Studiorösten ingår i Guldstjärnan. Be en vuxen att titta på det.',
            'tts_unavailable' => 'Studiorösten fungerar inte just nu. Appen använder telefonens röst så länge.',
        ],
    ];

    public function __construct(
        private readonly AppleTransactionVerifier $verifier,
        private readonly GooglePurchaseVerifier $google,
    ) {}

    /**
     * Formen på beviset.
     *
     * @throws GlosisRejection 422 invalid_proof
     */
    public function parse(mixed $proof, string $feature): StoreProof
    {
        if (! is_array($proof) || array_is_list($proof)) {
            throw $this->reject(422, 'invalid_proof', $feature);
        }

        $platform = $proof['platform'] ?? null;
        if ($platform === StoreProof::ANDROID) {
            $productId = $proof['productId'] ?? null;
            $token = $proof['purchaseToken'] ?? null;
            if (! is_string($productId) || $productId === '' || ! is_string($token) || $token === '') {
                throw $this->reject(422, 'invalid_proof', $feature);
            }

            return StoreProof::android($productId, $token);
        }
        if ($platform !== StoreProof::IOS || ! is_string($proof['jws'] ?? null) || $proof['jws'] === '') {
            throw $this->reject(422, 'invalid_proof', $feature);
        }

        return StoreProof::ios($proof['jws']);
    }

    /**
     * Verifierar köpet hos butiken och att det ger Guld just nu.
     *
     * @throws GlosisRejection 422 invalid_proof, 402 guld_required,
     *                         501 platform_not_supported (Google Play inte inställt),
     *                         503 scan_unavailable|tts_unavailable (Google gick inte att fråga)
     */
    public function verify(StoreProof $proof, Game $game, string $feature): VerifiedTransaction
    {
        $log = $feature === self::FEATURE_SCAN ? 'Glosis scan' : 'Glosis tts';

        try {
            $transaction = $proof->platform === StoreProof::ANDROID
                ? $this->verifyGoogle($proof, $game, $feature, $log)
                : $this->verifier->verify((string) $proof->jws);
        } catch (InvalidProofException $e) {
            Log::warning("{$log}: köpbevis avvisat", ['platform' => $proof->platform, 'reason' => $e->getMessage()]);

            throw $this->reject(422, 'invalid_proof', $feature);
        } catch (StoreUnavailableException $e) {
            Log::warning("{$log}: butiken gick inte att fråga", ['platform' => $proof->platform, 'reason' => $e->getMessage()]);

            throw $this->reject(503, $feature === self::FEATURE_SCAN ? 'scan_unavailable' : 'tts_unavailable', $feature);
        }

        // Apple: återbetalningen syns i JWS:ens revocationDate bara om appen
        // hämtat en ny; spärrlistan från RevenueCat-webhooken fångar ett gammalt
        // bevis. Google svarar med köpets aktuella status, spärrlistan gäller ändå.
        $denylisted = RevokedStoreTransaction::isRevoked($game, $transaction->originalTransactionId);
        if ($transaction->isRevoked() || $denylisted || ! GuldStatus::isActive($transaction->productId, now())) {
            Log::info("{$log}: inget giltigt Guld", [
                'platform' => $proof->platform,
                'environment' => $transaction->environment,
                'tx' => self::txHash($transaction),
                'product_id' => $transaction->productId,
                'revoked' => $transaction->isRevoked(),
                'store_revoked' => $transaction->storeRevoked,
                'denylisted' => $denylisted,
            ]);

            throw $this->reject(402, 'guld_required', $feature);
        }

        return $transaction;
    }

    private function verifyGoogle(StoreProof $proof, Game $game, string $feature, string $log): VerifiedTransaction
    {
        $settings = GlosisSettings::for($game);
        $account = $settings->googlePlayServiceAccount();
        if ($account === null) {
            // Felet om vad som saknas är redan loggat av GlosisSettings.
            Log::error("{$log}: Android-köp kan inte kontrolleras, Google Play-tjänstekontot saknas");

            throw $this->reject(501, 'platform_not_supported', $feature);
        }

        return $this->google->verify($account, $settings->googlePlayPackageName(), (string) $proof->productId, (string) $proof->purchaseToken);
    }

    /** Kort hash av köpet för loggar; själva id:t loggas aldrig. */
    public static function txHash(VerifiedTransaction $transaction): string
    {
        return substr(hash('sha256', $transaction->originalTransactionId), 0, 16);
    }

    private function reject(int $status, string $code, string $feature): GlosisRejection
    {
        return new GlosisRejection($status, $code, self::MESSAGES[$feature][$code]);
    }
}
