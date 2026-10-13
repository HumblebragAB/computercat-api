<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeDevkitTokens extends Command
{
    protected $signature = 'computercat:revoke-devkit-tokens';

    protected $description = 'Raderar Sanctum-tokens som Dev Kit skapat (namn "devkit"). Räknar och listar först.';

    public function handle(): int
    {
        $tokens = PersonalAccessToken::where('name', 'devkit')->get(['id', 'tokenable_id', 'created_at', 'last_used_at']);

        $this->line("{$tokens->count()} devkit-token(s).");
        $this->table(['id', 'user_id', 'skapad', 'senast använd'], $tokens->map(fn ($t) => [$t->id, $t->tokenable_id, $t->created_at, $t->last_used_at ?? '-'])->all());

        if ($tokens->isEmpty() || ! $this->confirm('Radera dessa tokens?')) {
            return self::SUCCESS;
        }

        // Raderar exakt de id:n som listades ovan.
        $deleted = PersonalAccessToken::whereIn('id', $tokens->pluck('id'))->delete();
        $this->info("Raderade {$deleted}.");

        return self::SUCCESS;
    }
}
