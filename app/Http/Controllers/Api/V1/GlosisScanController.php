<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\AiCost;
use App\Services\Glosis\BudgetReservation;
use App\Services\Glosis\FreeGate;
use App\Services\Glosis\GlosisCaller;
use App\Services\Glosis\GlosisRejection;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\GuldGate;
use App\Services\Glosis\GuldStatus;
use App\Services\Glosis\HomeworkScanner;
use App\Services\Glosis\ScanFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /api/v1/games/glosis/scan
 *
 * Glosis har inga konton. Rätten att skanna bevisas med ett köpbevis från
 * App Store (StoreKit 2) eller Google Play för ett giltigt Guld-läsår
 * (GuldGate), gränsen räknas per originalTransactionId (Google: orderId) och ISO-vecka i Stockholm, och varje anrop till Claude
 * bokförs i ai_usage mot en global månadsbudget (AiBudget).
 *
 * Gratisnivån (iOS, beslut 2026-10-12): i stället för proof skickas
 * attest = JSON-sträng {keyId, assertion, clientData} där clientData =
 * {"action":"scan","challenge":…,"imageSha256":"<hex av de uppladdade bytesen>"}.
 * Servern räknar om bildens SHA-256 och jämför, så att bilden inte går att byta
 * efter signeringen. Ett foto per nyckel och ISO-vecka (Stockholm), plus ett
 * globalt dagstak och ett dagstak per IP, och gratisnivåns delbudget.
 * proof och attest samtidigt: 422 invalid_request. Gratisfel: 429 free_scan_used
 * (retry_after till måndag), 429 free_busy, 401 unknown_key, 422 invalid_attest
 * (också fel bildhash), 503 free_unavailable.
 *
 * Integritet: bilden läses bara in i minnet från PHP:s tillfälliga
 * uppladdningsfil, sparas aldrig och loggas aldrig. Loggarna får bara
 * metadata (miljö, hashat transaktions-id, antal ord, tid, tokens).
 */
class GlosisScanController extends Controller
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /** Claude tar högst 8000 × 8000 px per bild. */
    public const MAX_IMAGE_DIMENSION = 8000;

    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Gånger per nyckel och vecka som gratisfotot återförs efter en oläsbar bild (inga ord eller scan_failed). */
    public const FREE_SCAN_WEEKLY_REFUNDS = 2;

    /**
     * Claude API: högst 10 MB per bild, base64-kodad
     * (https://platform.claude.com/docs/en/build-with-claude/vision#request-limits).
     * MAX_IMAGE_BYTES (5 MB rått, ~6,7 MB base64) håller sig under det; kontrollen
     * nedan gör gränsen uttrycklig om MAX_IMAGE_BYTES någon gång höjs.
     */
    public const MAX_IMAGE_BASE64_BYTES = 10 * 1024 * 1024;

    public static function weeklyLimitKey(string $originalTransactionId, \DateTimeInterface $now): string
    {
        // ISO-år (o) så att t.ex. 2026-12-31 hamnar i 2026-W53 och 2027-01-01 också.
        $week = CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->format('o-\WW');

        return 'glosis-scan:tx:'.hash('sha256', $originalTransactionId).':'.$week;
    }

    public function __invoke(Request $request, Game $game, GuldGate $gate, FreeGate $free, HomeworkScanner $scanner, AiBudget $budget): JsonResponse
    {
        try {
            return $this->scan($request, $game, $gate, $free, $scanner, $budget);
        } catch (GlosisRejection $e) {
            return $e->toResponse();
        }
    }

    private function scan(Request $request, Game $game, GuldGate $gate, FreeGate $free, HomeworkScanner $scanner, AiBudget $budget): JsonResponse
    {
        // has(): också ett tomt fält (null efter ConvertEmptyStringsToNull) räknas som skickat.
        $isFree = $request->has('attest');
        if ($isFree && $request->has('proof')) {
            return $this->error(422, 'invalid_request', 'Något blev fel. Försök igen.');
        }

        // 1. Köpbevisets form (multipart: beviset är en JSON-sträng). Gratis:
        // App Attest direkt, så att avstängd gratisnivå (503 free_unavailable),
        // okända nycklar och fel signatur stoppas innan bilden ens läses.
        $storeProof = null;
        $freeRequest = null;
        if ($isFree) {
            $attestRaw = $request->input('attest');
            $attest = is_string($attestRaw) && strlen($attestRaw) <= 20_000 ? json_decode($attestRaw, true, 4) : null;
            $freeRequest = $free->verify($attest, $game, GuldGate::FEATURE_SCAN, $request->ip());
        } else {
            $rawProof = $request->input('proof');
            $proof = is_string($rawProof) && strlen($rawProof) <= 20_000 ? json_decode($rawProof, true) : null;
            $storeProof = $gate->parse($proof, GuldGate::FEATURE_SCAN);
        }

        // 2. Bilden
        $image = $this->readImage($request->file('image'));
        if ($image === null) {
            return $this->error(422, 'invalid_image', 'Bilden gick inte att använda. Ta en ny bild (jpg, png eller webp, högst 5 MB).');
        }
        [$imageBytes, $mime] = $image;

        // 3–4. Guld: köpet hos Apple (offline) eller Google Play, Guld-läsår som
        // gäller, inte återbetalt. Gratis: bilden är den som signerades. Bilden är
        // det enda som inte ligger i clientData, så dess SHA-256 gör det; inget
        // annat fält läses ur den osignerade kroppen.
        $transaction = null;
        if ($freeRequest !== null) {
            $caller = $freeRequest->caller;
            $signed = $freeRequest->clientData['imageSha256'] ?? null;
            if (! is_string($signed) || strlen($signed) !== 64 || ! hash_equals(hash('sha256', $imageBytes), strtolower($signed))) {
                Log::warning('Glosis scan: bilden är inte den som signerades', ['key' => $caller->logHash()]);

                throw $free->reject(422, 'invalid_attest', GuldGate::FEATURE_SCAN);
            }
        } else {
            $transaction = $gate->verify($storeProof, $game, GuldGate::FEATURE_SCAN);
            $caller = GlosisCaller::guld($transaction);
        }
        $txHash = $caller->logHash();

        // 5. API-nyckeln
        $apiKey = GlosisSettings::for($game)->anthropicKey();
        if ($apiKey === null) {
            return $this->error(503, 'scan_unavailable', 'Att fota läxan fungerar inte just nu. Skriv in orden själv så länge.');
        }

        // 6. Månadsbudgeten (gratis: också gratisnivåns delbudget): reservera en
        // övre uppskattning, rättas efter svaret.
        $reservation = $budget->reserve($game, AiCost::SCAN_RESERVE_MICRO_USD, AiUsage::FEATURE_SCAN, $caller->tier);
        if ($reservation === null) {
            return $this->error(503, 'budget_exhausted', 'Fota läxan har tagit paus för den här månaden. Skriv in orden så länge.');
        }

        // 7. Veckogränsen. Räknas först och avgörs på värdet hit() returnerar:
        // cachens increment är atomisk (databas-store: lockForUpdate i en
        // transaktion), så parallella anrop kan inte alla se en räknare under
        // gränsen. Räknas bara när indata och köp eller nyckel godkänts.
        $nowStockholm = CarbonImmutable::now(GuldStatus::TIME_ZONE);
        $secondsToMonday = max(1, (int) ceil($nowStockholm->diffInSeconds($nowStockholm->startOfWeek(CarbonImmutable::MONDAY)->addWeek())));
        $refundWeekly = null;
        if ($transaction === null) {
            $refundWeekly = $this->freeLimits($game, $caller, $budget, $reservation, $free, $nowStockholm, $secondsToMonday);
        } else {
            // Guld: per köp och ISO-vecka i Stockholm (Filament:
            // settings.scan.weekly_limit, sandbox lägre).
            $hits = RateLimiter::hit(self::weeklyLimitKey($transaction->originalTransactionId, $nowStockholm), $secondsToMonday + 60);
            if ($hits > GlosisSettings::for($game)->scanWeeklyLimit($transaction->environment)) {
                $budget->release($reservation);
                Log::info('Glosis scan: veckogränsen nådd', ['environment' => $transaction->environment, 'tx' => $txHash]);

                throw new GlosisRejection(429, 'rate_limited', 'Du har fotat många läxor den här veckan. Försök igen på måndag!',
                    ['retry_after' => $secondsToMonday], ['Retry-After' => (string) $secondsToMonday]);
            }
        }

        // 8. Claude
        $started = microtime(true);
        try {
            $result = $scanner->scan($apiKey, $imageBytes, $mime);
        } catch (ScanFailedException $e) {
            if ($e->wasBilled()) {
                $this->recordUsage($game, $budget, $reservation, $caller, (string) $e->model, $e->inputTokens, $e->outputTokens);
            } else {
                $budget->release($reservation);
            }
            $refunded = $refundWeekly !== null && $this->refundFreeScan($refundWeekly, $caller, $secondsToMonday);
            Log::warning('Glosis scan: avläsningen misslyckades', [
                'environment' => $caller->environment,
                'tier' => $caller->tier,
                'tx' => $txHash,
                'reason' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'input_tokens' => $e->inputTokens,
                'output_tokens' => $e->outputTokens,
                'weekly_refunded' => $refunded,
            ]);

            return $this->error(502, 'scan_failed', 'Jag kunde inte läsa bilden. Ta en ny bild rakt ovanifrån i bra ljus och försök igen.');
        } catch (Throwable $e) {
            $budget->release($reservation);
            if ($refundWeekly !== null) {
                $this->refundFreeScan($refundWeekly, $caller, $secondsToMonday);
            }

            throw $e;
        }

        $this->recordUsage($game, $budget, $reservation, $caller, $result->model, $result->inputTokens, $result->outputTokens);

        // Inga ord: barnet ska inte förlora veckans foto på en suddig bild.
        $refunded = $refundWeekly !== null && count($result->words) === 0
            && $this->refundFreeScan($refundWeekly, $caller, $secondsToMonday);

        Log::info('Glosis scan: klar', [
            'environment' => $caller->environment,
            'tier' => $caller->tier,
            'tx' => $txHash,
            'words' => count($result->words),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'weekly_refunded' => $refunded,
        ]);

        return response()->json($result->toResponse());
    }

    /**
     * Gratisgränserna: veckans foto per nyckel, ett globalt dagstak och ett
     * dagstak per IP. Räknas först och avgörs på värdet hit() returnerar, som
     * Guld-gränsen. Returnerar veckoräknarens nyckel, så att den kan återföras
     * om bilden inte gick att läsa. Dagstaken återförs aldrig: de begränsar
     * också återförda anrop till Claude.
     *
     * @throws GlosisRejection 429 free_scan_used | free_busy
     */
    private function freeLimits(Game $game, GlosisCaller $caller, AiBudget $budget, BudgetReservation $reservation, FreeGate $free, CarbonImmutable $now, int $secondsToMonday): string
    {
        $settings = GlosisSettings::for($game);
        $day = $now->format('Y-m-d');
        $dayTtl = (int) ceil($now->diffInSeconds($now->addDay()->startOfDay())) + 60;
        $weekTtl = $secondsToMonday + 60;
        $weekKey = self::freeWeekKey($caller, $now);
        $globalKey = 'glosis-scan:free-day:'.$game->id.':'.$day;
        $ipKey = 'glosis-scan:free-ip:'.$caller->ipLimitId().':'.$day;

        if (RateLimiter::hit($weekKey, $weekTtl) > GlosisSettings::FREE_SCAN_WEEKLY_PER_KEY) {
            RateLimiter::decrement($weekKey, $weekTtl);
            $budget->release($reservation);
            Log::info('Glosis scan: veckans gratisfoto använt', ['key' => $caller->logHash()]);

            throw $free->reject(429, 'free_scan_used', GuldGate::FEATURE_SCAN, ['retry_after' => $secondsToMonday], ['Retry-After' => (string) $secondsToMonday]);
        }
        $globalOver = RateLimiter::hit($globalKey, $dayTtl) > $settings->freeScanDailyLimit();
        $ipOver = RateLimiter::hit($ipKey, $dayTtl) > $settings->freeScanIpDailyLimit();
        if ($globalOver || $ipOver) {
            RateLimiter::decrement($weekKey, $weekTtl);
            RateLimiter::decrement($globalKey, $dayTtl);
            RateLimiter::decrement($ipKey, $dayTtl);
            $budget->release($reservation);
            Log::warning('Glosis scan: gratistaket nått', ['key' => $caller->logHash(), 'scope' => $globalOver ? 'global' : 'ip']);

            throw $free->reject(429, 'free_busy', GuldGate::FEATURE_SCAN);
        }

        return $weekKey;
    }

    public static function freeWeekKey(GlosisCaller $caller, \DateTimeInterface $now): string
    {
        return 'glosis-scan:free:'.$caller->limitId().':'.CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->format('o-\WW');
    }

    /**
     * Återför veckans gratisfoto efter en oläsbar bild. Högst
     * FREE_SCAN_WEEKLY_REFUNDS gånger per nyckel och vecka, så att tomma
     * bilder inte ger obegränsat med anrop till Claude.
     */
    private function refundFreeScan(string $weekKey, GlosisCaller $caller, int $secondsToMonday): bool
    {
        $ttl = $secondsToMonday + 60;
        if (RateLimiter::hit($weekKey.':refunds', $ttl) > self::FREE_SCAN_WEEKLY_REFUNDS) {
            Log::info('Glosis scan: veckans återföringar av gratisfotot är slut', ['key' => $caller->logHash()]);

            return false;
        }
        RateLimiter::decrement($weekKey, $ttl);

        return true;
    }

    private function recordUsage(Game $game, AiBudget $budget, BudgetReservation $reservation, GlosisCaller $caller, string $model, int $inputTokens, int $outputTokens): void
    {
        if ($model !== AiCost::SCAN_MODEL) {
            // Reservmodell (server-side fallback): priset är inte inlagt.
            Log::warning('Glosis scan: modellen saknas i prislistan, räknar med '.AiCost::SCAN_MODEL.'s pris', ['model' => $model]);
        }

        $budget->record($game, $reservation, AiUsage::FEATURE_SCAN, $caller->environment, AiCost::scan($inputTokens, $outputTokens), [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ], $caller);
    }

    /**
     * Läser uppladdningen till minnet och kontrollerar det verkliga innehållet
     * (finfo på bytes, inte filändelse eller klientens Content-Type).
     *
     * @return array{0: string, 1: string}|null
     */
    private function readImage(mixed $file): ?array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }
        $size = $file->getSize();
        if (! is_int($size) || $size <= 0 || $size > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $bytes = file_get_contents($file->getRealPath(), length: self::MAX_IMAGE_BYTES + 1);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        if (4 * (int) ceil(strlen($bytes) / 3) > self::MAX_IMAGE_BASE64_BYTES) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            return null;
        }

        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > self::MAX_IMAGE_DIMENSION || $dimensions[1] > self::MAX_IMAGE_DIMENSION) {
            return null;
        }

        return [$bytes, $mime];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
