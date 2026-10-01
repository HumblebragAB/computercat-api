<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\RevokedStoreTransaction;
use App\Services\Glosis\AppleTransactionVerifier;
use App\Services\Glosis\GuldStatus;
use App\Services\Glosis\HomeworkScanner;
use App\Services\Glosis\InvalidProofException;
use App\Services\Glosis\ScanFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /api/v1/games/glosis/scan
 *
 * Glosis har inga konton. Rätten att skanna bevisas med ett StoreKit
 * 2-köpbevis för ett giltigt Guld-läsår, och gränsen räknas per
 * originalTransactionId och Stockholmsdygn.
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

    /** Riktiga köp. Sandbox-köp: config('services.glosis.sandbox_daily_scans'). */
    public const DAILY_SCANS_PER_TRANSACTION = 30;

    public static function dailyLimitKey(string $originalTransactionId, \DateTimeInterface $now): string
    {
        $day = CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->format('Y-m-d');

        return 'glosis-scan:tx:'.hash('sha256', $originalTransactionId).':'.$day;
    }

    private static function dailyLimit(string $environment): int
    {
        return $environment === 'Production'
            ? self::DAILY_SCANS_PER_TRANSACTION
            : max(0, (int) config('services.glosis.sandbox_daily_scans', 5));
    }

    public function __invoke(Request $request, Game $game, AppleTransactionVerifier $verifier, HomeworkScanner $scanner): JsonResponse
    {
        // 1. Köpbevisets form
        $rawProof = $request->input('proof');
        $proof = is_string($rawProof) && strlen($rawProof) <= 20_000 ? json_decode($rawProof, true) : null;
        if (! is_array($proof)) {
            return $this->error(422, 'invalid_proof', 'Vi kunde inte kontrollera köpet av Guldstjärnan. Försök igen.');
        }
        $platform = $proof['platform'] ?? null;
        if ($platform === 'android') {
            return $this->error(501, 'platform_not_supported', 'Att fota läxan fungerar inte på Android än. Skriv in orden själv så länge.');
        }
        if ($platform !== 'ios' || ! is_string($proof['jws'] ?? null) || $proof['jws'] === '') {
            return $this->error(422, 'invalid_proof', 'Vi kunde inte kontrollera köpet av Guldstjärnan. Försök igen.');
        }

        // 2. Bilden
        $image = $this->readImage($request->file('image'));
        if ($image === null) {
            return $this->error(422, 'invalid_image', 'Bilden gick inte att använda. Ta en ny bild (jpg, png eller webp, högst 5 MB).');
        }
        [$imageBytes, $mime] = $image;

        // 3. Verifiera köpet hos Apple (offline, mot Apple Root CA - G3)
        try {
            $transaction = $verifier->verify($proof['jws']);
        } catch (InvalidProofException $e) {
            Log::warning('Glosis scan: köpbevis avvisat', ['reason' => $e->getMessage()]);

            return $this->error(422, 'invalid_proof', 'Vi kunde inte kontrollera köpet av Guldstjärnan. Försök igen.');
        }
        $txHash = substr(hash('sha256', $transaction->originalTransactionId), 0, 16);

        // 4. Guld för ett läsår som fortfarande gäller, och inte återbetalt.
        // Återbetalningen syns i JWS:ens revocationDate bara om appen hämtat en
        // ny; spärrlistan från RevenueCat-webhooken fångar ett gammalt bevis.
        $denylisted = RevokedStoreTransaction::isRevoked($game, $transaction->originalTransactionId);
        if ($transaction->isRevoked() || $denylisted || ! GuldStatus::isActive($transaction->productId, now())) {
            Log::info('Glosis scan: inget giltigt Guld', [
                'environment' => $transaction->environment,
                'tx' => $txHash,
                'product_id' => $transaction->productId,
                'revoked' => $transaction->isRevoked(),
                'denylisted' => $denylisted,
            ]);

            return $this->error(402, 'guld_required', 'Att fota läxan ingår i Guldstjärnan. Be en vuxen att titta på det.');
        }

        // 5. API-nyckeln
        $apiKey = $this->apiKey($game);
        if ($apiKey === null) {
            return response()->json([
                'error' => 'scan_unavailable',
                'message' => 'Att fota läxan fungerar inte just nu. Skriv in orden själv så länge.',
            ], 503);
        }

        // 6. Dagsgräns per köp och Stockholmsdygn (30, sandbox lägre). Räknas
        // först och avgörs på värdet hit() returnerar: cachens increment är
        // atomisk (databas-store: lockForUpdate i en transaktion), så parallella
        // anrop kan inte alla se en räknare under gränsen. Räknas bara när
        // indata och köp redan godkänts.
        $nowStockholm = CarbonImmutable::now(GuldStatus::TIME_ZONE);
        $secondsToMidnight = max(1, (int) ceil($nowStockholm->diffInSeconds($nowStockholm->addDay()->startOfDay())));
        $hits = RateLimiter::hit(self::dailyLimitKey($transaction->originalTransactionId, $nowStockholm), $secondsToMidnight + 60);
        if ($hits > self::dailyLimit($transaction->environment)) {
            Log::info('Glosis scan: dagsgränsen nådd', ['environment' => $transaction->environment, 'tx' => $txHash]);

            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Du har fotat många läxor i dag. Försök igen i morgon!',
                'retry_after' => $secondsToMidnight,
            ], 429, ['Retry-After' => (string) $secondsToMidnight]);
        }

        // 7. Claude
        $started = microtime(true);
        try {
            $result = $scanner->scan($apiKey, $imageBytes, $mime);
        } catch (ScanFailedException $e) {
            Log::warning('Glosis scan: avläsningen misslyckades', [
                'environment' => $transaction->environment,
                'tx' => $txHash,
                'reason' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $this->error(502, 'scan_failed', 'Jag kunde inte läsa bilden. Ta en ny bild rakt ovanifrån i bra ljus och försök igen.');
        }

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

    private function apiKey(Game $game): ?string
    {
        $encrypted = $game->settings['anthropic']['api_key'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            Log::error("Glosis scan: ingen Anthropic-nyckel för {$game->slug}");

            return null;
        }

        try {
            $key = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            Log::error("Glosis scan: Anthropic-nyckeln för {$game->slug} går inte att dekryptera");

            return null;
        }

        return $key !== '' ? $key : null;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
