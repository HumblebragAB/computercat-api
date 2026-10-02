<?php

namespace App\Services\Glosis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ElevenLabs text-to-speech, "Create speech":
 * https://elevenlabs.io/docs/api-reference/text-to-speech/convert (läst 2026-10-02)
 *
 *   POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}?output_format=mp3_44100_64
 *   Header xi-api-key
 *   JSON {text, model_id, language_code, voice_settings: {stability, similarity_boost, speed}}
 *   200: ljudet som binär kropp. 422: {"detail": ...}.
 *
 * voice_settings.speed: 1.0 är normal takt, 0.7 är API:ts minimum.
 * stability och similarity_boost skickas med dokumentationens standardvärden.
 */
class ElevenLabsClient
{
    public const ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech/';

    public const MODEL = 'eleven_flash_v2_5';

    public const OUTPUT_FORMAT = 'mp3_44100_64';

    /** Standardspråk. language_code är ISO 639-1 (en, de, es, fr). */
    public const LANGUAGE = 'en';

    public const STABILITY = 0.5;

    public const SIMILARITY_BOOST = 0.75;

    /** Ett ord är några kB; ett svar över 1 MB är inte ett ord. */
    public const MAX_AUDIO_BYTES = 1024 * 1024;

    /** Loggad del av request och svar vid fel. */
    private const LOG_BYTES = 4096;

    /**
     * @return string MP3-data
     *
     * @throws TtsFailedException
     */
    public function synthesize(string $apiKey, string $voiceId, string $text, float $speed, string $language = self::LANGUAGE): string
    {
        $body = [
            'text' => $text,
            'model_id' => self::MODEL,
            'language_code' => $language,
            'voice_settings' => [
                'stability' => self::STABILITY,
                'similarity_boost' => self::SIMILARITY_BOOST,
                'speed' => $speed,
            ],
        ];
        $url = self::ENDPOINT.rawurlencode($voiceId).'?output_format='.self::OUTPUT_FORMAT;

        try {
            $response = Http::withHeaders(['xi-api-key' => $apiKey, 'Accept' => 'audio/mpeg'])
                ->connectTimeout(5)
                ->timeout(20)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new TtsFailedException('ElevenLabs gick inte att nå: '.mb_substr($e->getMessage(), 0, 300), ['request' => $body], $e);
        }

        if (! $response->successful()) {
            throw new TtsFailedException('ElevenLabs svarade '.$response->status(), [
                'status' => $response->status(),
                'request' => $body,
                'response' => mb_strcut($response->body(), 0, self::LOG_BYTES),
            ]);
        }

        $audio = $response->body();
        if ($audio === '' || strlen($audio) > self::MAX_AUDIO_BYTES || ! self::looksLikeMp3($audio)) {
            throw new TtsFailedException('ElevenLabs svar är inte MP3', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'bytes' => strlen($audio),
            ]);
        }

        return $audio;
    }

    /** ID3-tagg eller en MPEG-ram (11 bitars synk). */
    public static function looksLikeMp3(string $bytes): bool
    {
        if (str_starts_with($bytes, 'ID3')) {
            return true;
        }

        return strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0;
    }
}
