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

    /** Språk som studiorösten kan ha en egen röst för (ISO 639-1 => namn i Filament). */
    public const LANGUAGES = ['en' => 'Engelska', 'de' => 'Tyska', 'es' => 'Spanska', 'fr' => 'Franska'];

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

    /**
     * Den aktiva studiorösten för ett språk: settings.elevenlabs.voices.{språk}.voice_id,
     * vald på sidan Röster. För engelska gäller det äldre fältet
     * settings.elevenlabs.voice_id som reserv när ingen röst är vald där.
     */
    public function elevenLabsVoiceId(string $language = 'en'): ?string
    {
        $voice = data_get($this->settings, "elevenlabs.voices.{$language}.voice_id");
        $path = "elevenlabs.voices.{$language}.voice_id";
        if ((! is_string($voice) || trim($voice) === '') && $language === 'en') {
            $voice = data_get($this->settings, 'elevenlabs.voice_id');
            $path = 'elevenlabs.voice_id';
        }
        if (! is_string($voice) || trim($voice) === '') {
            Log::error("Glosis: ingen ElevenLabs-röst för språket {$language} i {$this->slug}");

            return null;
        }
        $voice = trim($voice);
        // Röst-id:t hamnar i adressen till ElevenLabs.
        if (! self::validVoiceId($voice)) {
            Log::error("Glosis: settings.{$path} för {$this->slug} har fel form");

            return null;
        }

        return $voice;
    }

    /**
     * Rösterna som visas på sidan Röster, per språk i LANGUAGES.
     *
     * @return array<string, array{voice_id: string, name: string|null, category: string|null, source: 'voices'|'legacy'}|null>
     */
    public function elevenLabsVoices(): array
    {
        $voices = [];
        foreach (array_keys(self::LANGUAGES) as $language) {
            $entry = data_get($this->settings, "elevenlabs.voices.{$language}");
            if (is_array($entry) && is_string($entry['voice_id'] ?? null) && $entry['voice_id'] !== '') {
                $voices[$language] = [
                    'voice_id' => $entry['voice_id'],
                    'name' => is_string($entry['name'] ?? null) ? $entry['name'] : null,
                    'category' => is_string($entry['category'] ?? null) ? $entry['category'] : null,
                    'source' => 'voices',
                ];
            } elseif ($language === 'en' && is_string($legacy = data_get($this->settings, 'elevenlabs.voice_id')) && trim($legacy) !== '') {
                $voices[$language] = ['voice_id' => trim($legacy), 'name' => null, 'category' => null, 'source' => 'legacy'];
            } else {
                $voices[$language] = null;
            }
        }

        return $voices;
    }

    public static function validVoiceId(mixed $voiceId): bool
    {
        return is_string($voiceId) && preg_match('/^[A-Za-z0-9]{1,64}\z/', $voiceId) === 1;
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
