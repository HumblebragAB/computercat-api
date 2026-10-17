<?php

namespace App\Matches;

use LogicException;
use RuntimeException;

/**
 * Inbjudningar till väntande matcher. Typen väljs per regeluppsättning med `invite` i
 * config/matches.php:
 *
 * - `token` (standard när `invite` saknas): 32 slumpbyte som base64url, 43 tecken, för
 *   länk/QR. Lagras som sha256; rymden är för stor för att hashen ska gå att vända.
 * - `code`: 4 tecken ur ALPHABET (utan 0/O/1/I/L) som motspelaren skriver in för hand.
 *   31^4 ≈ 923 000 koder går att räkna igenom offline på ett ögonblick, så koden lagras
 *   som HMAC-SHA256 med app.key, aldrig som ren sha256 eller klartext. Den som läser
 *   databasen men inte har nyckeln kan inte vända hashen.
 *
 * Båda lagras i join_token_hash. Inmatning skiljs åt på formen: efter normalisering
 * (blanksteg bort, versaler) ser en kod ut som /^[A-Z2-9]{4}$/, vilket en 43 teckens
 * token aldrig gör. Token normaliseras inte (skiftlägeskänslig).
 */
class InviteTokens
{
    public const TYPE_TOKEN = 'token';

    public const TYPE_CODE = 'code';

    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 4;

    private const CODE_SHAPE = '/\A[A-Z2-9]{4}\z/';

    /** Inbjudningstypen för en regeluppsättning. Okänt värde är ett konfigurationsfel. */
    public static function typeFor(string $ruleset): string
    {
        $type = config("matches.rulesets.{$ruleset}.invite") ?? self::TYPE_TOKEN;

        if (! in_array($type, [self::TYPE_TOKEN, self::TYPE_CODE], true)) {
            throw new LogicException("Unknown invite type for ruleset {$ruleset}.");
        }

        return $type;
    }

    public function generate(string $type): string
    {
        return match ($type) {
            self::TYPE_CODE => $this->generateCode(),
            self::TYPE_TOKEN => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            default => throw new LogicException("Unknown invite type {$type}."),
        };
    }

    /** Hashen som lagras och slås upp för en inbjudan, som skapad eller som inskriven. */
    public function hash(string $input): string
    {
        $code = strtoupper((string) preg_replace('/\s+/u', '', $input));

        if (preg_match(self::CODE_SHAPE, $code) === 1) {
            $key = (string) config('app.key');
            if ($key === '') {
                throw new RuntimeException('app.key saknas; inbjudningskoder kan inte hashas.');
            }

            return hash_hmac('sha256', $code, $key);
        }

        return hash('sha256', $input);
    }

    /** Söm för tester (framkallad krock). */
    protected function generateCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
