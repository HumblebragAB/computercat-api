<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use App\Services\Glosis\GlosisSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Glosis gratisnivå: App Attest (iOS). Inga konton; en installation av appen
 * har en nyckel som Apple intygat. Flöde enligt Apples "Establishing your app's
 * integrity": challenge → attestKey(SHA256(challenge)) → register.
 *
 * Svaren är generiska (invalid_attestation); orsaken står bara i loggen, med
 * AppAttestKey::hashForLog i stället för keyId. Challenge, attestering och
 * nycklar loggas aldrig.
 */
class GlosisAttestController extends Controller
{
    private const UNAVAILABLE = 'Gratisfunktionerna är pausade just nu. Försök igen senare.';

    private const INVALID = 'Appen kunde inte kontrolleras. Försök igen om en stund.';

    /** base64 av en attestering (ungefär 5,5 kB rå i praktiken) med god marginal. */
    private const MAX_ATTESTATION_B64 = 22_000;

    /**
     * Gräns för vår egen json_decode. Den skyddar inte mot stora anrop: Laravels globala TrimStrings och
     * ConvertEmptyStringsToNull har redan tolkat JSON-kroppen innan controllern körs (som för TTS-routen).
     * Storleken på hela anropet begränsas av nginx (client_max_body_size) och PHP (post_max_size).
     */
    private const MAX_BODY_BYTES = 24_000;

    public function challenge(Game $game, AttestChallenges $challenges): JsonResponse
    {
        if ($this->appId($game) === null) {
            return $this->error(503, 'free_unavailable', self::UNAVAILABLE);
        }

        return response()->json(['challenge' => $challenges->issue(), 'expires_in' => AttestChallenges::TTL_SECONDS]);
    }

    public function register(Request $request, Game $game, AppAttestVerifier $verifier, AttestChallenges $challenges): JsonResponse
    {
        $appId = $this->appId($game);
        if ($appId === null) {
            return $this->error(503, 'free_unavailable', self::UNAVAILABLE);
        }

        $content = $request->getContent();
        $body = strlen($content) <= self::MAX_BODY_BYTES ? json_decode($content, true, 4) : null;
        $keyIdB64 = is_array($body) ? ($body['keyId'] ?? null) : null;
        $attB64 = is_array($body) ? ($body['attestation'] ?? null) : null;
        $challenge = is_array($body) ? ($body['challenge'] ?? null) : null;
        $keyId = is_string($keyIdB64) && strlen($keyIdB64) === 44 ? base64_decode($keyIdB64, true) : false;
        $attestation = is_string($attB64) && $attB64 !== '' && strlen($attB64) <= self::MAX_ATTESTATION_B64 ? base64_decode($attB64, true) : false;
        if ($keyId === false || strlen($keyId) !== 32 || $attestation === false || $attestation === '') {
            Log::info('Glosis attest: registrering med fel form');

            return $this->error(422, 'invalid_attestation', self::INVALID);
        }
        $keyHash = AppAttestKey::hashForLog(base64_encode($keyId));
        // Challengen förbrukas före den dyra kontrollen: en avvisad attestering bränner den, och samma
        // challenge kan aldrig prövas två gånger.
        if (! $challenges->consume($challenge)) {
            Log::info('Glosis attest: ogiltig, utgången eller redan använd challenge', ['key' => $keyHash]);

            return $this->error(422, 'invalid_attestation', self::INVALID);
        }

        try {
            // Rå challenge: verifieraren räknar clientDataHash = SHA256(challengen) själv, som appen.
            $attested = $verifier->verifyAttestation($attestation, $keyId, $challenge, $appId, now()->getTimestamp(), allowDevelopment: ! app()->isProduction());
        } catch (InvalidAttestationException $e) {
            Log::warning('Glosis attest: attesteringen avvisad', ['key' => $keyHash, 'reason' => $e->getMessage()]);

            return $this->error(422, 'invalid_attestation', self::INVALID);
        }

        $canonical = base64_encode($attested->keyId);
        try {
            $key = AppAttestKey::create([
                'game_id' => $game->id, 'key_id' => $canonical, 'public_key' => $attested->publicKeyPem,
                'sign_count' => 0, 'environment' => $attested->environment,
                'bundle_version' => $attested->bundleVersion, 'validation_category' => $attested->validationCategory,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->reRegistered($game, $canonical, $attested->publicKeyPem);
        }
        Log::info('Glosis attest: ny nyckel', [
            'key' => $key->logHash(), 'environment' => $attested->environment,
            'bundle_version' => $attested->bundleVersion, 'validation_category' => $attested->validationCategory,
        ]);

        return response()->json(['status' => 'registered'], 201);
    }

    /**
     * Samma nyckel, nytt giltigt intyg (appen fick inget svar förra gången). Raden rörs inte, så
     * räknaren kan inte nollställas och en replay av gamla assertioner öppnas inte.
     *
     * ASSUMED: inte prövat mot riktig enhet. Apple kan vägra attestera samma nyckel två gånger, och
     * då används vägen aldrig. Den är bara testad med FakeAppAttest. Publika nyckeln ska vara
     * densamma eftersom keyId = SHA256(nyckeln) och attesteringen nyss verifierats; det kontrolleras
     * ändå, så en avvikelse syns i loggen i stället för att godtas.
     */
    private function reRegistered(Game $game, string $keyId, string $publicKeyPem): JsonResponse
    {
        $existing = AppAttestKey::where('game_id', $game->id)->where('key_id', $keyId)->first();
        if ($existing === null || $existing->public_key !== $publicKeyPem) {
            Log::error('Glosis attest: sparad nyckel matchar inte den nya attesteringen', ['key' => AppAttestKey::hashForLog($keyId)]);

            return $this->error(422, 'invalid_attestation', self::INVALID);
        }
        Log::info('Glosis attest: redan registrerad nyckel attesterad igen', ['key' => $existing->logHash()]);

        return response()->json(['status' => 'registered']);
    }

    /** App ID för attesteringen, eller null när gratisnivån är avstängd eller Team ID saknas (503). Läses en gång per anrop. */
    private function appId(Game $game): ?string
    {
        $settings = GlosisSettings::for($game);

        return $settings->freeEnabled() ? $settings->appAttestAppId() : null;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
