<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Gallrar övergivna DCR-klienter. POST /oauth/register (laravel/mcp, oautentiserad) skapar en
 * oauth_clients-rad per anrop, även när ingen inloggning följer.
 *
 * En DCR-klient känns igen på exakt det OAuthRegisterController skapar via
 * ClientRepository::createAuthorizationCodeGrantClient(confidential: false, enableDeviceFlow: false):
 * ingen ägare, ingen secret (publik), ingen provider och grant_types exakt
 * [authorization_code, refresh_token]. Klienter med access-tokens, auth-koder eller
 * device-koder rörs aldrig. Räknar först och raderar sedan exakt de insamlade id:na.
 *
 * Invariant: schemalägg aldrig `passport:purge` utan `--revoked`. Rensas utgångna access-tokens
 * bort medan deras refresh-tokens fortfarande gäller ser en aktiv klient oanvänd ut, och då
 * raderar den här gallringen den.
 */
class PruneOAuthClients extends Command
{
    private const DCR_GRANT_TYPES = ['authorization_code', 'refresh_token'];

    private const CHUNK = 500;

    protected $signature = 'computercat:prune-oauth-clients {--hours=24} {--dry-run}';

    protected $description = 'Raderar DCR-registrerade OAuth-klienter som aldrig använts';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $ids = $this->unused(Passport::client()->newQuery())
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('secret')
            ->whereNull('provider')
            ->where('created_at', '<', $cutoff)
            ->get(['id', 'grant_types'])
            // grant_types är JSON-text; jämförs i PHP i stället för som sträng i SQL.
            ->filter(fn (Client $client) => $this->isDcrGrantTypes($client->grant_types))
            ->pluck('id');

        $message = sprintf('%d oanvänd(a) DCR-klient(er) äldre än %d h.', $ids->count(), $hours);
        $this->info($message);
        Log::info('computercat:prune-oauth-clients: '.$message, ['dry_run' => (bool) $this->option('dry-run')]);

        if ($this->option('dry-run') || $ids->isEmpty()) {
            return self::SUCCESS;
        }

        $deleted = $this->deleteUnused($ids->all());
        $this->info("Raderade {$deleted}.");
        Log::info("computercat:prune-oauth-clients: raderade {$deleted}.");
        if ($deleted < $ids->count()) {
            Log::warning('computercat:prune-oauth-clients: färre raderade än valda, klienter fick tokens eller koder under körningen.', [
                'selected' => $ids->count(), 'deleted' => $deleted,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Raderar exakt de givna id:na i omgångar, men bara de som fortfarande saknar tokens och
     * koder. Villkoren upprepas eftersom en klient kan få sin första kod mellan urval och radering.
     *
     * @param  array<int, string>  $ids
     */
    public function deleteUnused(array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $deleted += $this->unused(Passport::client()->newQuery())->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }

    /** Klienten har inga access-tokens, auth-koder eller device-koder. */
    private function unused(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('tokens')
            ->whereDoesntHave('authCodes')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('oauth_device_codes')
                ->whereColumn('oauth_device_codes.client_id', 'oauth_clients.id'));
    }

    /** @param  array<int, string>  $grantTypes */
    private function isDcrGrantTypes(array $grantTypes): bool
    {
        sort($grantTypes);

        return $grantTypes === self::DCR_GRANT_TYPES;
    }
}
