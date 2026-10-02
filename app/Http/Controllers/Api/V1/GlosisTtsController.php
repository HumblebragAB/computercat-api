<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Glosis\GlosisRejection;
use App\Services\Glosis\GuldGate;
use App\Services\Glosis\StudioVoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Glosis studioröst (iOS, Guldstjärnan).
 *
 * POST /api/v1/games/glosis/tts/prepare
 *   {"proof": {"platform": "ios", "jws": "..."}, "speed": "normal"|"slow", "words": ["dog", ...]}
 *   200 {"urls": {"<ord som skickats>": "<signerad adress>"}, "skipped": [{"word": "...", "reason": "invalid|limit|unavailable"}]}
 *   Fel i skanningens form: 402 guld_required, 422 invalid_proof|invalid_request,
 *   429 rate_limited, 501 platform_not_supported, 503 tts_unavailable|budget_exhausted.
 *
 * GET /api/v1/games/glosis/tts/audio/{hash} (signerad, 24 h) → audio/mpeg.
 */
class GlosisTtsController extends Controller
{
    public function prepare(Request $request, Game $game, GuldGate $gate, StudioVoice $voice): JsonResponse
    {
        try {
            // Rå JSON i stället för $request->input(): TrimStrings och
            // ConvertEmptyStringsToNull skulle ändra orden, och svaret ska ha
            // orden exakt som appen skickade dem som nycklar.
            $body = json_decode($request->getContent(), true, 16);
            $body = is_array($body) ? $body : [];

            $jws = $gate->parse($body['proof'] ?? null, GuldGate::FEATURE_TTS);

            $speed = $body['speed'] ?? null;
            $words = $body['words'] ?? null;
            if (! is_string($speed) || ! array_key_exists($speed, StudioVoice::SPEEDS) || ! self::validWords($words)) {
                throw new GlosisRejection(422, 'invalid_request', 'Något blev fel med studiorösten. Appen använder telefonens röst så länge.');
            }

            $transaction = $gate->verify($jws, $game, GuldGate::FEATURE_TTS);
            $result = $voice->prepare($game, $transaction, $speed, $words);
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
        return response()->json(['urls' => (object) $result['urls'], 'skipped' => $result['skipped']]);
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
