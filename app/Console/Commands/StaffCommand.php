<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

// Enda vägen att sätta staff-flaggorna. Visar kontot först, eftersom
// e-postadresser är overifierade och en spelare kan ha registrerat en
// adress som liknar personalens.
class StaffCommand extends Command
{
    protected $signature = 'computercat:staff {email} {--grant} {--revoke} {--admin : Ge även plattformsadmin (alla spel)}';

    protected $description = 'Ger eller tar bort staff-behörighet (panel + MCP). Visar kontot och kräver bekräftelse.';

    public function handle(): int
    {
        if ($this->option('grant') === $this->option('revoke')) {
            $this->error('Ange exakt en av --grant och --revoke.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user || $user->is_anonymous) {
            $this->error('Inget icke-anonymt konto med den adressen.');

            return self::FAILURE;
        }

        $this->line("Konto: id {$user->id}, {$user->email}, skapat {$user->created_at}, senast sedd {$user->last_seen_at}, bannat: ".($user->is_banned ? 'ja' : 'nej'));

        if ($this->option('grant')) {
            if (! $this->confirm('Ge staff-behörighet till detta konto?')) {
                return self::FAILURE;
            }
            // Utan --admin behålls en befintlig admin-flagga; den tas bara bort med --revoke.
            $user->forceFill(['is_staff' => true, 'is_platform_admin' => $this->option('admin') || $user->is_platform_admin])->save();
        } else {
            if (! $this->confirm('Ta bort staff-behörighet från detta konto?')) {
                return self::FAILURE;
            }
            $user->forceFill(['is_staff' => false, 'is_platform_admin' => false])->save();
        }

        $this->info('Klart.');

        return self::SUCCESS;
    }
}
