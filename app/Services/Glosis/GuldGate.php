<?php

namespace App\Services\Glosis;

use App\Models\Game;
use App\Models\RevokedStoreTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Rätten att använda en Guld-funktion (fotoskanning, studioröst). Glosis har
 * inga konton: appen skickar ett StoreKit 2-köpbevis
 * {"platform": "ios", "jws": "..."} som verifieras offline mot Apples rot.
 *
 * Två steg så att anroparen kan kontrollera sin egen indata emellan:
 * parse() (formen, Android → 501) och verify() (Apple, Guld-läsår, spärrlista).
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
        ],
        self::FEATURE_TTS => [
            'invalid_proof' => 'Vi kunde inte kontrollera köpet av Guldstjärnan. Försök igen.',
            'platform_not_supported' => 'Studiorösten fungerar inte på Android än. Appen använder telefonens röst så länge.',
            'guld_required' => 'Studiorösten ingår i Guldstjärnan. Be en vuxen att titta på det.',
        ],
    ];

    public function __construct(private readonly AppleTransactionVerifier $verifier) {}

    /**
     * Formen på beviset. Returnerar JWS:en.
     *
     * @throws GlosisRejection 422 invalid_proof, 501 platform_not_supported
     */
    public function parse(mixed $proof, string $feature): string
    {
        if (! is_array($proof) || array_is_list($proof)) {
            throw $this->reject(422, 'invalid_proof', $feature);
        }
        $platform = $proof['platform'] ?? null;
        if ($platform === 'android') {
            throw $this->reject(501, 'platform_not_supported', $feature);
        }
        if ($platform !== 'ios' || ! is_string($proof['jws'] ?? null) || $proof['jws'] === '') {
            throw $this->reject(422, 'invalid_proof', $feature);
        }

        return $proof['jws'];
    }

    /**
     * Verifierar köpet hos Apple (offline) och att det ger Guld just nu.
     *
     * @throws GlosisRejection 422 invalid_proof, 402 guld_required
     */
    public function verify(string $jws, Game $game, string $feature): VerifiedTransaction
    {
        $log = $feature === self::FEATURE_SCAN ? 'Glosis scan' : 'Glosis tts';

        try {
            $transaction = $this->verifier->verify($jws);
        } catch (InvalidProofException $e) {
            Log::warning("{$log}: köpbevis avvisat", ['reason' => $e->getMessage()]);

            throw $this->reject(422, 'invalid_proof', $feature);
        }

        // Återbetalningen syns i JWS:ens revocationDate bara om appen hämtat en
        // ny; spärrlistan från RevenueCat-webhooken fångar ett gammalt bevis.
        $denylisted = RevokedStoreTransaction::isRevoked($game, $transaction->originalTransactionId);
        if ($transaction->isRevoked() || $denylisted || ! GuldStatus::isActive($transaction->productId, now())) {
            Log::info("{$log}: inget giltigt Guld", [
                'environment' => $transaction->environment,
                'tx' => self::txHash($transaction),
                'product_id' => $transaction->productId,
                'revoked' => $transaction->isRevoked(),
                'denylisted' => $denylisted,
            ]);

            throw $this->reject(402, 'guld_required', $feature);
        }

        return $transaction;
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
