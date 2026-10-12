<?php

namespace App\Services\Glosis;

use App\Models\AppAttestKey;
use RuntimeException;

/**
 * Vem som gör ett betalt anrop: ett Guld-köp, en gratisnyckel (App Attest)
 * eller provlyssningen i Filament. Gränserna, budgeten och ai_usage räknar på det.
 */
final readonly class GlosisCaller
{
    public const GULD = 'guld';

    public const FREE = 'free';

    public const ADMIN = 'admin';

    private function __construct(
        public string $tier,
        /** Underlag för räknarnycklar. Loggas aldrig i klartext. */
        private string $subject,
        /** Production, Sandbox eller Admin (som VerifiedTransaction och ai_usage.environment). */
        public string $environment,
        /** Identifieraren i loggar: GuldGate::txHash() för köp, AppAttestKey::logHash() för nycklar. */
        private string $logId,
        public ?int $attestKeyId = null,
        public ?string $ip = null,
    ) {}

    public static function guld(VerifiedTransaction $transaction): self
    {
        return new self(self::GULD, $transaction->originalTransactionId, $transaction->environment, GuldGate::txHash($transaction));
    }

    /** Nyckelns logghash är samma som i FreeGate och registreringen, så en nyckel har en identifierare i alla loggar. */
    public static function free(AppAttestKey $key, string $ip): self
    {
        return new self(
            self::FREE,
            'attest-key:'.$key->id,
            $key->environment === AppAttestKey::ENV_PRODUCTION ? 'Production' : 'Sandbox',
            $key->logHash(),
            $key->id,
            $ip,
        );
    }

    /** Provlyssningen i Filament. Samma räknarnyckel ('filament-preview') och logghash som före gratisnivån. */
    public static function admin(): self
    {
        return new self(self::ADMIN, 'filament-preview', 'Admin', substr(hash('sha256', 'filament-preview'), 0, 16));
    }

    public function isFree(): bool
    {
        return $this->tier === self::FREE;
    }

    /** Räknarnyckelns id. Guld: 'tx:' + sha256(köpet), exakt som före gratisnivån, så att räknarna fortsätter över driftsättningen. */
    public function limitId(): string
    {
        return ($this->isFree() ? 'key:' : 'tx:').hash('sha256', $this->subject);
    }

    /**
     * Räknarnyckelns id för IP-gränsen: HMAC med en nyckel ur APP_KEY, så att
     * en IP-adress inte går att få fram ur cachen genom att prova alla adresser.
     */
    public function ipLimitId(): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '' || $this->ip === null) {
            throw new RuntimeException('IP-gränsens nyckel kräver APP_KEY och en gratisanropare med IP');
        }

        return hash_hmac('sha256', $this->ip, hash_hmac('sha256', 'glosis-ip-limit', $appKey, true));
    }

    public function logHash(): string
    {
        return $this->logId;
    }
}
