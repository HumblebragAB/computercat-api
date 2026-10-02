<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\AiCost;
use App\Services\Glosis\BudgetReservation;
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
 * Glosis har inga konton. Rätten att skanna bevisas med ett StoreKit
 * 2-köpbevis för ett giltigt Guld-läsår (GuldGate), gränsen räknas per
 * originalTransactionId och ISO-vecka i Stockholm, och varje anrop till Claude
 * bokförs i ai_usage mot en global månadsbudget (AiBudget).
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

    public function __invoke(Request $request, Game $game, GuldGate $gate, HomeworkScanner $scanner, AiBudget $budget): JsonResponse
    {
        try {
            return $this->scan($request, $game, $gate, $scanner, $budget);
        } catch (GlosisRejection $e) {
            return $e->toResponse();
        }
    }

    private function scan(Request $request, Game $game, GuldGate $gate, HomeworkScanner $scanner, AiBudget $budget): JsonResponse
    {
        // 1. Köpbevisets form (multipart: beviset är en JSON-sträng)
        $rawProof = $request->input('proof');
        $proof = is_string($rawProof) && strlen($rawProof) <= 20_000 ? json_decode($rawProof, true) : null;
        $jws = $gate->parse($proof, GuldGate::FEATURE_SCAN);

        // 2. Bilden
        $image = $this->readImage($request->file('image'));
        if ($image === null) {
            return $this->error(422, 'invalid_image', 'Bilden gick inte att använda. Ta en ny bild (jpg, png eller webp, högst 5 MB).');
        }
        [$imageBytes, $mime] = $image;

        // 3–4. Köpet hos Apple (offline), Guld-läsår som gäller, inte återbetalt
        $transaction = $gate->verify($jws, $game, GuldGate::FEATURE_SCAN);
        $txHash = GuldGate::txHash($transaction);

        // 5. API-nyckeln
        $apiKey = GlosisSettings::for($game)->anthropicKey();
        if ($apiKey === null) {
            return $this->error(503, 'scan_unavailable', 'Att fota läxan fungerar inte just nu. Skriv in orden själv så länge.');
        }

        // 6. Månadsbudgeten: reservera en övre uppskattning, rättas efter svaret.
        $reservation = $budget->reserve($game, AiCost::SCAN_RESERVE_MICRO_USD, AiUsage::FEATURE_SCAN);
        if ($reservation === null) {
            return $this->error(503, 'budget_exhausted', 'Fota läxan har tagit paus för den här månaden. Skriv in orden så länge.');
        }

        // 7. Veckogräns per köp och ISO-vecka i Stockholm (Filament:
        // settings.scan.weekly_limit, sandbox lägre). Räknas först och avgörs på
        // värdet hit() returnerar: cachens increment är atomisk (databas-store:
        // lockForUpdate i en transaktion), så parallella anrop kan inte alla se
        // en räknare under gränsen. Räknas bara när indata och köp godkänts.
        $nowStockholm = CarbonImmutable::now(GuldStatus::TIME_ZONE);
        $secondsToMonday = max(1, (int) ceil($nowStockholm->diffInSeconds($nowStockholm->startOfWeek(CarbonImmutable::MONDAY)->addWeek())));
        $hits = RateLimiter::hit(self::weeklyLimitKey($transaction->originalTransactionId, $nowStockholm), $secondsToMonday + 60);
        if ($hits > GlosisSettings::for($game)->scanWeeklyLimit($transaction->environment)) {
            $budget->release($reservation);
            Log::info('Glosis scan: veckogränsen nådd', ['environment' => $transaction->environment, 'tx' => $txHash]);

            throw new GlosisRejection(429, 'rate_limited', 'Du har fotat många läxor den här veckan. Försök igen på måndag!',
                ['retry_after' => $secondsToMonday], ['Retry-After' => (string) $secondsToMonday]);
        }

        // 8. Claude
        $started = microtime(true);
        try {
            $result = $scanner->scan($apiKey, $imageBytes, $mime);
        } catch (ScanFailedException $e) {
            if ($e->wasBilled()) {
                $this->recordUsage($game, $budget, $reservation, $transaction->environment, (string) $e->model, $e->inputTokens, $e->outputTokens);
            } else {
                $budget->release($reservation);
            }
            Log::warning('Glosis scan: avläsningen misslyckades', [
                'environment' => $transaction->environment,
                'tx' => $txHash,
                'reason' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'input_tokens' => $e->inputTokens,
                'output_tokens' => $e->outputTokens,
            ]);

            return $this->error(502, 'scan_failed', 'Jag kunde inte läsa bilden. Ta en ny bild rakt ovanifrån i bra ljus och försök igen.');
        } catch (Throwable $e) {
            $budget->release($reservation);

            throw $e;
        }

        $this->recordUsage($game, $budget, $reservation, $transaction->environment, $result->model, $result->inputTokens, $result->outputTokens);

        Log::info('Glosis scan: klar', [
            'environment' => $transaction->environment,
            'tx' => $txHash,
            'words' => count($result->words),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);

        return response()->json($result->toResponse());
    }

    private function recordUsage(Game $game, AiBudget $budget, BudgetReservation $reservation, string $environment, string $model, int $inputTokens, int $outputTokens): void
    {
        if ($model !== AiCost::SCAN_MODEL) {
            // Reservmodell (server-side fallback): priset är inte inlagt.
            Log::warning('Glosis scan: modellen saknas i prislistan, räknar med '.AiCost::SCAN_MODEL.'s pris', ['model' => $model]);
        }

        $budget->record($game, $reservation, AiUsage::FEATURE_SCAN, $environment, AiCost::scan($inputTokens, $outputTokens), [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);
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
