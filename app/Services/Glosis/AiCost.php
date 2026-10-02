<?php

namespace App\Services\Glosis;

/**
 * Uppskattad kostnad för betalda AI-anrop, i miljondels dollar (heltal, så att
 * budgeträknaren kan räknas upp atomiskt).
 *
 * Skanning: claude-sonnet-5-5, $2 per miljon input-token och $10 per miljon
 * output-token (claude-api-skillens modelltabell, 2026-10-02), alltså 2 resp.
 * 10 miljondels dollar per token.
 * Studioröst: ElevenLabs eleven_flash_v2_5, uppskattat $0.04 per 1000 tecken,
 * alltså 40 miljondels dollar per tecken.
 */
final class AiCost
{
    public const SCAN_MODEL = HomeworkScanner::MODEL;

    public const SCAN_INPUT_MICRO_USD_PER_TOKEN = 2;

    public const SCAN_OUTPUT_MICRO_USD_PER_TOKEN = 10;

    /**
     * Reserveras före varje skanning och rättas efter svaret. Täcker en stor
     * bild (~10 000 input-token) plus max_tokens output (4000): $0.06. En vanlig
     * skanning kostar under $0.01.
     */
    public const SCAN_RESERVE_MICRO_USD = 10_000 * self::SCAN_INPUT_MICRO_USD_PER_TOKEN
        + HomeworkScanner::MAX_TOKENS * self::SCAN_OUTPUT_MICRO_USD_PER_TOKEN;

    public const TTS_MICRO_USD_PER_CHARACTER = 40;

    public static function scan(int $inputTokens, int $outputTokens): int
    {
        return max(0, $inputTokens) * self::SCAN_INPUT_MICRO_USD_PER_TOKEN
            + max(0, $outputTokens) * self::SCAN_OUTPUT_MICRO_USD_PER_TOKEN;
    }

    public static function tts(int $characters): int
    {
        return max(0, $characters) * self::TTS_MICRO_USD_PER_CHARACTER;
    }

    public static function toUsd(int $microUsd): string
    {
        return number_format($microUsd / 1_000_000, 6, '.', '');
    }
}
