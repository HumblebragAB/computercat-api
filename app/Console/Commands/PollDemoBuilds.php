<?php

namespace App\Console\Commands;

use App\Provisioning\ProvisioningConfig;
use App\Releases\DemoBuildRecorder;
use App\Support\SlackAlert;
use Illuminate\Console\Command;

// Spärr 1 behöver ett kvitto per lyckat Demo-bygge, och Coolifys lista visar bara de
// senaste. Kommandot läser listan för varje spel med Demo och sparar nya kvitton.
class PollDemoBuilds extends Command
{
    protected $signature = 'computercat:poll-demo-builds';

    protected $description = 'Sparar kvitton för lyckade Demo-byggen ur Coolifys bygglista';

    public function handle(DemoBuildRecorder $recorder, SlackAlert $slack): int
    {
        $missing = ProvisioningConfig::missing();
        if ($missing !== []) {
            $this->error('Provisioneringen är inte konfigurerad, saknas: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $result = $recorder->recordAll();
        foreach ($result['failed'] as $slug => $message) {
            $this->line("FEL {$slug}: {$message}");
            // Samma nyckel skickas högst en gång per dygn (SlackAlert), så pollen tjatar inte.
            $slack->send("poll-demo-builds:{$slug}", 'computercat:poll-demo-builds kunde inte läsa byggen för `'.SlackAlert::escape($slug).'`: '.SlackAlert::escape($message));
        }
        $this->info("{$result['recorded']} nya kvitton, ".count($result['failed']).' fel.');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
