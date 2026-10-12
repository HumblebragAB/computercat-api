<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Databascachen (CACHE_STORE=database i produktion) tar aldrig bort utgångna rader själv.
 * Gratisnivån skriver räknare per nyckel, IP och dygn och en markör per inlöst challenge.
 * De utgångna raderna gallras här. Rate limiter-rader ingår inte: Laravel lagrar throttle-nycklar
 * som md5(...) och de börjar aldrig med "glosis-".
 *
 * Bara nycklar som börjar med någon av Glosis egna prefix nedan (efter cache.prefix) och bara
 * utgångna rader. Prefixen matchas exakt i PHP, inte med LIKE, så ingen annan apps rader kan
 * träffas av ett jokertecken. Räknar först, och raderar exakt de nycklar som räknades.
 */
class PruneGlosisCache extends Command
{
    /** Det Glosis skriver i cachen: AttestChallenges, FreeScanWeek och GlosisScanController, StudioVoice, AiBudget. */
    public const PREFIXES = [
        'glosis-attest-used:',
        'glosis-scan:',
        'glosis-tts:',
        'glosis-ai-budget:',
        'glosis-ai-budget-free:',
    ];

    protected $signature = 'glosis:prune-cache {--dry-run}';

    protected $description = 'Raderar utgångna Glosis-rader i databascachen';

    public function handle(): int
    {
        if (config('cache.default') !== 'database') {
            $this->info('Cachen är inte databas-store, inget att gallra.');

            return self::SUCCESS;
        }
        $store = config('cache.stores.database');
        $table = DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache');
        $now = now()->getTimestamp();
        $cachePrefix = (string) config('cache.prefix');

        $keys = [];
        foreach (self::PREFIXES as $prefix) {
            $full = $cachePrefix.$prefix;
            // SUBSTR-jämförelse smalnar av i databasen; str_starts_with är den exakta kontrollen.
            $candidates = (clone $table)->where('expiration', '<', $now)
                ->whereRaw('substr('.$this->col($store, 'key').', 1, ?) = ?', [strlen($full), $full])
                ->pluck('key');
            foreach ($candidates as $key) {
                if (str_starts_with($key, $full)) {
                    $keys[$key] = true;
                }
            }
        }
        $keys = array_keys($keys);
        $count = count($keys);
        $this->info("{$count} utgångna Glosis-rader i cachen.");
        if ($this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }
        $deleted = 0;
        foreach (array_chunk($keys, 500) as $chunk) {
            // Utgångskravet upprepas så en rad som förnyats under tiden inte raderas.
            $deleted += (clone $table)->whereIn('key', $chunk)->where('expiration', '<', $now)->delete();
        }
        $this->info("Raderade {$deleted}.");

        return self::SUCCESS;
    }

    private function col(array $store, string $column): string
    {
        return DB::connection($store['connection'] ?? null)->getQueryGrammar()->wrap($column);
    }
}
