<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// request_live skrev aldrig något till databasen (bara Slack). Det enda beständiga spåret
// är revisionsloggen. Aktiva spel med en lyckad request_live får målet web och det öppna
// engångssteget web_public_address (med den önskade domänen ur loggen, om den fanns) (plan 2026-10-14-slappflode-1a, R3). Ingen produktionsrätt
// följer av det: adressen måste sättas och första produktionssläppet godkännas av Erik.
// Texten i steget är en ögonblicksbild av SetupSteps::DEFINITIONS['web'] vid skrivtillfället;
// migrationer läser aldrig appens klasser.
return new class extends Migration
{
    private const WEB_STEP_KEY = 'web_public_address';

    private const WEB_STEP_TEXT = 'Erik sätter spelets publika webbadress (domänen) för produktionswebben.';

    public function up(): void
    {
        // Nyast först: den senaste angivna domänen per spel vinner.
        $rows = DB::table('mcp_audit_logs')->where('tool', 'request_live')->where('outcome', 'ok')
            ->whereNotNull('game_id')->orderByDesc('id')->get(['game_id', 'arguments']);

        $gameIds = [];
        $domains = [];
        foreach ($rows as $row) {
            $gameIds[$row->game_id] = true;
            if (isset($domains[$row->game_id])) {
                continue;
            }
            $arguments = json_decode((string) $row->arguments, true);
            $domain = is_array($arguments) && is_string($arguments['domain'] ?? null) ? strtolower(trim($arguments['domain'])) : '';
            // Samma slags värde som request_live accepterade: ett värdnamn, inget schema, ingen sökväg.
            if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $domain) === 1) {
                $domains[$row->game_id] = $domain;
            }
        }

        foreach (array_keys($gameIds) as $gameId) {
            // Arkiverade (inaktiva) spel får inget mål.
            $game = DB::table('games')->where('id', $gameId)->where('is_active', true)->first(['id', 'release_targets']);
            if ($game === null) {
                continue;
            }

            $targets = json_decode((string) $game->release_targets, true);
            $targets = is_array($targets) ? $targets : [];
            if (! in_array('web', $targets, true)) {
                $targets[] = 'web';
            }
            DB::table('games')->where('id', $game->id)->update(['release_targets' => json_encode(array_values($targets))]);

            // Unikt på (game_id, target, key): körs migrationen igen rörs ett klart steg inte.
            DB::table('release_setup_steps')->insertOrIgnore([
                'game_id' => $game->id,
                'target' => 'web',
                'key' => self::WEB_STEP_KEY,
                'status' => 'open',
                'instruction' => self::WEB_STEP_TEXT.(isset($domains[$gameId]) ? ' Önskad adress enligt Live-förfrågan: '.$domains[$gameId].'.' : ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Avsiktligt tom: en återställning skulle också ta bort mål som Erik satt efteråt.
    }
};
