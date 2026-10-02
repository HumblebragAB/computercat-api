<?php

namespace App\Services\Glosis;

use App\Models\Game;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Glosis gränser och nycklar ur games.settings, redigerbara i Filament
 * (GameResource, sektionen "Glosis: AI-kostnader"). Saknat värde ger
 * standardvärdet; ett ogiltigt värde ger standardvärdet och en varning i loggen.
 */
final class GlosisSettings
{
    public const DEFAULT_SCAN_WEEKLY_LIMIT = 15;

    public const DEFAULT_SCAN_SANDBOX_WEEKLY_LIMIT = 5;

    public const DEFAULT_MONTHLY_BUDGET_USD = 50;

    public const DEFAULT_TTS_DAILY_NEW_LIMIT = 1000;

    /** Nya ord per köp och Stockholmsdygn. Cachade ord räknas inte. */
    public const TTS_DAILY_NEW_PER_PURCHASE = 100;

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings, private readonly string $slug = 'glosis') {}

    public static function for(Game $game): self
    {
        return new self($game->settings ?? [], $game->slug);
    }

    /** Skanningar per köp och ISO-vecka i Stockholm. */
    public function scanWeeklyLimit(string $environment): int
    {
        return $environment === 'Production'
            ? (int) $this->number('scan.weekly_limit', self::DEFAULT_SCAN_WEEKLY_LIMIT)
            : (int) $this->number('scan.sandbox_weekly_limit', self::DEFAULT_SCAN_SANDBOX_WEEKLY_LIMIT);
    }

    /** Månadsbudget för alla betalda AI-anrop, i miljondels dollar. */
    public function monthlyBudgetMicroUsd(): int
    {
        return (int) round($this->number('ai.monthly_budget_usd', self::DEFAULT_MONTHLY_BUDGET_USD) * 1_000_000);
    }

    public function ttsDailyNewLimit(): int
    {
        return (int) $this->number('tts.daily_new_limit', self::DEFAULT_TTS_DAILY_NEW_LIMIT);
    }

    public function anthropicKey(): ?string
    {
        return $this->secret('anthropic.api_key', 'Anthropic-nyckel');
    }

    public function elevenLabsKey(): ?string
    {
        return $this->secret('elevenlabs.api_key', 'ElevenLabs-nyckel');
    }

    public function elevenLabsVoiceId(): ?string
    {
        $voice = data_get($this->settings, 'elevenlabs.voice_id');
        if (! is_string($voice) || trim($voice) === '') {
            Log::error("Glosis: inget ElevenLabs voice_id för {$this->slug}");

            return null;
        }
        $voice = trim($voice);
        // Röst-id:t hamnar i adressen till ElevenLabs.
        if (preg_match('/^[A-Za-z0-9]{1,64}\z/', $voice) !== 1) {
            Log::error("Glosis: ElevenLabs voice_id för {$this->slug} har fel form");

            return null;
        }

        return $voice;
    }

    private function number(string $path, int|float $default): int|float
    {
        $value = data_get($this->settings, $path);
        if ($value === null || $value === '') {
            return $default;
        }
        if (! is_numeric($value) || $value < 0) {
            Log::warning("Glosis: ogiltigt värde i settings.{$path} för {$this->slug}, använder {$default}");

            return $default;
        }

        return $value + 0;
    }

    private function secret(string $path, string $name): ?string
    {
        $encrypted = data_get($this->settings, $path);
        if (! is_string($encrypted) || $encrypted === '') {
            Log::error("Glosis: ingen {$name} för {$this->slug}");

            return null;
        }

        try {
            $key = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            Log::error("Glosis: {$name} för {$this->slug} går inte att dekryptera");

            return null;
        }

        return $key !== '' ? $key : null;
    }
}
