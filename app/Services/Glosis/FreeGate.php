<?php

namespace App\Services\Glosis;

use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use Illuminate\Support\Facades\Log;

/**
 * Gratisnivåns grind (beslut 2026-10-12). Appen skickar
 *   "attest": {"keyId": "<base64>", "assertion": "<base64>", "clientData": "<JSON-sträng>"}
 * där clientData = {"action": "scan"|"tts", "challenge": "<engångs>", ...förfrågans fält}.
 * clientData är det appen signerat, och förfrågans innehåll läses därifrån.
 *
 * Ordning: form → nyckeln finns → signatur, RP ID och räknare
 * (AppAttestVerifier) → challengen löses in (engångs) → räknaren sparas atomiskt.
 */
final class FreeGate
{
    private const MESSAGES = [
        GuldGate::FEATURE_SCAN => [
            'free_unavailable' => 'Gratisfotot är pausat just nu. Skriv in orden själv så länge.',
            'invalid_attest' => 'Appen kunde inte kontrolleras. Försök igen om en stund.',
            'unknown_key' => 'Appen behöver kontrolleras på nytt. Försök igen.',
            'free_scan_used' => 'Du har använt veckans gratisfoto. Nästa går att ta på måndag. Med Guldstjärnan kan du fota fler läxor.',
            'free_busy' => 'Många fotar sina läxor just nu. Försök igen i morgon, eller skriv in orden så länge.',
        ],
        GuldGate::FEATURE_TTS => [
            'free_unavailable' => 'Studiorösten är pausad just nu. Appen använder telefonens röst så länge.',
            'invalid_attest' => 'Studiorösten kunde inte starta. Appen använder telefonens röst så länge.',
            'unknown_key' => 'Studiorösten behöver starta om. Appen använder telefonens röst så länge.',
        ],
    ];

    public function __construct(
        private readonly AppAttestVerifier $verifier,
        private readonly AttestChallenges $challenges,
    ) {}

    /** @throws GlosisRejection */
    public function verify(mixed $attest, Game $game, string $feature, ?string $ip): FreeRequest
    {
        $settings = GlosisSettings::for($game);
        $appId = $settings->freeEnabled() ? $settings->appAttestAppId() : null;
        if ($appId === null) {
            throw $this->reject(503, 'free_unavailable', $feature);
        }

        $keyIdB64 = is_array($attest) ? ($attest['keyId'] ?? null) : null;
        $assertionB64 = is_array($attest) ? ($attest['assertion'] ?? null) : null;
        $clientData = is_array($attest) ? ($attest['clientData'] ?? null) : null;
        $keyId = is_string($keyIdB64) && strlen($keyIdB64) === 44 ? base64_decode($keyIdB64, true) : false;
        $assertion = is_string($assertionB64) && strlen($assertionB64) <= 4096 ? base64_decode($assertionB64, true) : false;
        $data = is_string($clientData) && strlen($clientData) <= 8192 ? json_decode($clientData, true, 8) : null;
        if ($keyId === false || strlen($keyId) !== 32 || $assertion === false || ! is_array($data) || array_is_list($data)
            || ($data['action'] ?? null) !== $feature || ! is_string($data['challenge'] ?? null)) {
            Log::info("Glosis {$feature}: gratisförfrågan med fel form");

            throw $this->reject(422, 'invalid_attest', $feature);
        }

        $key = AppAttestKey::where('game_id', $game->id)->where('key_id', base64_encode($keyId))->first();
        if ($key === null) {
            Log::info("Glosis {$feature}: okänd nyckel", ['key' => AppAttestKey::hashForLog(base64_encode($keyId))]);

            throw $this->reject(401, 'unknown_key', $feature);
        }

        try {
            $count = $this->verifier->verifyAssertion($assertion, $clientData, $key->public_key, $key->sign_count, $appId);
        } catch (InvalidAttestationException $e) {
            Log::warning("Glosis {$feature}: assertion avvisad", ['key' => $key->logHash(), 'reason' => $e->getMessage()]);

            throw $this->reject(422, 'invalid_attest', $feature);
        }
        if (! $this->challenges->consume($data['challenge'])) {
            Log::info("Glosis {$feature}: challenge ogiltig, utgången eller använd", ['key' => $key->logHash()]);

            throw $this->reject(422, 'invalid_attest', $feature);
        }
        if (! $key->advanceSignCount($count)) {
            Log::warning("Glosis {$feature}: räknaren hann flyttas av ett annat anrop (replay?)", ['key' => $key->logHash()]);

            throw $this->reject(422, 'invalid_attest', $feature);
        }

        return new FreeRequest(GlosisCaller::free($key, $ip), $data);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public function reject(int $status, string $code, string $feature, array $extra = [], array $headers = []): GlosisRejection
    {
        return new GlosisRejection($status, $code, self::MESSAGES[$feature][$code], $extra, $headers);
    }
}
