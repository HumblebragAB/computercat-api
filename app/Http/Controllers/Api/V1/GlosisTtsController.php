<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Glosis\ElevenLabsClient;
use App\Services\Glosis\FreeGate;
use App\Services\Glosis\GlosisCaller;
use App\Services\Glosis\GlosisRejection;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\GuldGate;
use App\Services\Glosis\StudioVoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Glosis studioröst (Guldstjärnan, iOS och Android; gratisnivån på iOS).
 *
 * POST /api/v1/games/glosis/tts/prepare
 *   {"proof": {"platform": "ios", "jws": "..."} eller
 *             {"platform": "android", "productId": "...", "purchaseToken": "..."}, "speed": "normal"|"slow", "words": ["dog", ...],
 *    "language": "en"|"de"|"es"|"fr" (valfritt, standard "en"),
 *    "voice": "female"|"male" (valfritt, standard "female")}
 *   Rösterna är de som är valda för språket på sidan Röster i Filament. Saknar
 *   språket den begärda rösten används den andra; voice_used säger vilken.
 *   200 {"urls": {"<ord som skickats>": "<signerad adress>"}, "skipped": [{"word": "...", "reason": "invalid|limit|unavailable"}],
 *        "voice_used": "female"|"male"}
 *   Fel i skanningens form: 402 guld_required, 422 invalid_proof|invalid_request,
 *   429 rate_limited, 501 platform_not_supported (Android när Google Play inte är
 *   inställt), 503 tts_unavailable|budget_exhausted.
 *
 *   Gratisnivån (iOS, App Attest) i stället för proof:
 *   {"attest": {"keyId": "...", "assertion": "...", "clientData": "<JSON-sträng>"}}
 *   där clientData = {"action": "tts", "challenge": "...", "speed": ..., "words": [...],
 *   "voice": ... (valfritt), "language": ... (valfritt)}. Fälten läses BARA ur den
 *   signerade clientData, aldrig ur kroppen. Svaret 200 har samma form som för Guld.
 *   proof och attest samtidigt: 422 invalid_request; inget av dem: 422 invalid_proof.
 *   Gratisfel: 401 unknown_key, 422 invalid_attest, 503 free_unavailable.
 *
 * GET /api/v1/games/glosis/tts/audio/{hash} (signerad, 24 h) → audio/mpeg.
 */
class GlosisTtsController extends Controller
{
    private const INVALID_REQUEST = 'Något blev fel med studiorösten. Appen använder telefonens röst så länge.';

    public function prepare(Request $request, Game $game, GuldGate $gate, FreeGate $free, StudioVoice $voice): JsonResponse
    {
        try {
            // Rå JSON i stället för $request->input(): TrimStrings och
            // ConvertEmptyStringsToNull skulle ändra orden, och svaret ska ha
            // orden exakt som appen skickade dem som nycklar.
            $body = json_decode($request->getContent(), true, 16);
            $body = is_array($body) ? $body : [];
            $hasAttest = array_key_exists('attest', $body);
            if ($hasAttest && array_key_exists('proof', $body)) {
                throw new GlosisRejection(422, 'invalid_request', self::INVALID_REQUEST);
            }

            if ($hasAttest) {
                // Gratisnivån: förfrågan är clientData, som appen signerat med
                // App Attest. Inget fält läses ur den osignerade kroppen.
                $freeRequest = $free->verify($body['attest'], $game, GuldGate::FEATURE_TTS, $request->ip());
                $params = $freeRequest->clientData;
                $caller = $freeRequest->caller;
            } else {
                $proof = $gate->parse($body['proof'] ?? null, GuldGate::FEATURE_TTS);
                $params = $body;
                $caller = null;
            }

            $speed = $params['speed'] ?? null;
            $words = $params['words'] ?? null;
            // Valfritt; saknas det gäller engelska (appar före språkfältet).
            $language = array_key_exists('language', $params) ? $params['language'] : ElevenLabsClient::LANGUAGE;
            // Valfritt; saknas det gäller kvinnlig röst (appar före röstvalet).
            $gender = array_key_exists('voice', $params) ? $params['voice'] : GlosisSettings::DEFAULT_GENDER;
            if (! is_string($speed) || ! array_key_exists($speed, StudioVoice::SPEEDS) || ! self::validWords($words)
                || ! is_string($language) || ! array_key_exists($language, GlosisSettings::LANGUAGES)
                || ! is_string($gender) || ! array_key_exists($gender, GlosisSettings::GENDERS)) {
                // En gratisförfrågan har här redan förbrukat sin challenge och
                // räknare. Avsiktligt: den var signerad, och appen hämtar en ny.
                throw new GlosisRejection(422, 'invalid_request', self::INVALID_REQUEST);
            }

            $caller ??= GlosisCaller::guld($gate->verify($proof, $game, GuldGate::FEATURE_TTS));
            $result = $voice->prepare($game, $caller, $speed, $words, $language, $gender);
        } catch (GlosisRejection $e) {
            return $e->toResponse();
        }

        // Gick inget ord att servera för att tjänsten (inte orden) stoppade:
        // ett tydligt 503 så att appen visar att studiorösten inte är tillgänglig.
        $unavailable = in_array('unavailable', array_column($result['skipped'], 'reason'), true);
        if ($result['urls'] === [] && $unavailable) {
            return $result['stopped'] === 'budget'
                ? response()->json(['error' => 'budget_exhausted', 'message' => 'Studiorösten har tagit paus för den här månaden. Appen använder telefonens röst så länge.'], 503)
                : response()->json(['error' => 'tts_unavailable', 'message' => 'Studiorösten fungerar inte just nu. Appen använder telefonens röst så länge.'], 503);
        }

        // (object): en tom lista ska vara {} i JSON, inte [].
        return response()->json(['urls' => (object) $result['urls'], 'skipped' => $result['skipped'], 'voice_used' => $result['voice_used']]);
    }

    public function audio(string $hash): BinaryFileResponse
    {
        $disk = Storage::disk(StudioVoice::DISK);
        $file = StudioVoice::fileName($hash);
        abort_unless($disk->exists($file), 404);

        // BinaryFileResponse svarar på Range-förfrågningar (206), som Safari och
        // WKWebView använder för <audio>. Den sätter "public" som standard;
        // adressen är personlig (signerad), så private.
        $response = response()->file($disk->path($file), ['Content-Type' => 'audio/mpeg']);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    private static function validWords(mixed $words): bool
    {
        if (! is_array($words) || ! array_is_list($words) || $words === [] || count($words) > StudioVoice::MAX_WORDS) {
            return false;
        }
        foreach ($words as $word) {
            if (! is_string($word) || strlen($word) > 1000) {
                return false;
            }
        }

        return true;
    }
}
