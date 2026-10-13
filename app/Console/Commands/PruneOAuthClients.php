<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

        $ids = Passport::client()->newQuery()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('secret')
            ->whereNull('provider')
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('tokens')
            ->whereDoesntHave('authCodes')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('oauth_device_codes')
                ->whereColumn('oauth_device_codes.client_id', 'oauth_clients.id'))
            ->get()
            // grant_types är JSON-text; jämförs i PHP i stället för som sträng i SQL.
            ->filter(fn (Client $client) => $this->isDcrGrantTypes($client->grant_types))
            ->pluck('id');

        $message = sprintf('%d oanvänd(a) DCR-klient(er) äldre än %d h.', $ids->count(), $hours);
        $this->info($message);
        Log::info('computercat:prune-oauth-clients: '.$message, ['dry_run' => (bool) $this->option('dry-run')]);

        if ($this->option('dry-run') || $ids->isEmpty()) {
            return self::SUCCESS;
        }

        // Raderar exakt de id:n som räknades ovan, i omgångar.
        $deleted = 0;
        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $deleted += Passport::client()->newQuery()->whereIn('id', $chunk->values()->all())->delete();
        }
        $this->info("Raderade {$deleted}.");
        Log::info("computercat:prune-oauth-clients: raderade {$deleted}.");

        return self::SUCCESS;
    }

    /** @param  array<int, string>  $grantTypes */
    private function isDcrGrantTypes(array $grantTypes): bool
    {
        sort($grantTypes);

        return $grantTypes === self::DCR_GRANT_TYPES;
    }
}
