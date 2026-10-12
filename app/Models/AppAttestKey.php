<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** En attesterad App Attest-nyckel (Glosis gratisnivå). Se migrationen. */
class AppAttestKey extends Model
{
    public const ENV_PRODUCTION = 'production';

    public const ENV_DEVELOPMENT = 'development';

    protected $table = 'glosis_app_attest_keys';

    protected $fillable = ['game_id', 'key_id', 'public_key', 'sign_count', 'environment', 'bundle_version', 'validation_category', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['sign_count' => 'integer', 'validation_category' => 'integer', 'last_used_at' => 'datetime'];
    }

    /**
     * Sparar en ny räknare bara om den är större än den sparade. Ett villkorat
     * UPDATE är atomiskt i databasen: av två parallella anrop med samma
     * assertion får bara det ena 1 påverkad rad.
     */
    public function advanceSignCount(int $count): bool
    {
        $updated = static::whereKey($this->getKey())->where('sign_count', '<', $count)
            ->update(['sign_count' => $count, 'last_used_at' => now()]);
        if ($updated === 1) {
            $this->sign_count = $count;
        }

        return $updated === 1;
    }

    /** Nyckelns enda identifierare i loggar och ai_usage-kontext; keyId loggas aldrig. */
    public function logHash(): string
    {
        return self::hashForLog($this->key_id);
    }

    /** Samma hash som logHash(), för en keyId (base64, som appen skickar den) som inte är sparad. */
    public static function hashForLog(string $keyIdBase64): string
    {
        return substr(hash('sha256', $keyIdBase64), 0, 16);
    }
}
