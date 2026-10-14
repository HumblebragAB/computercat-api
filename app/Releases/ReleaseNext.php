<?php

namespace App\Releases;

// ALLA `next`-texter finns här och bara här (spec: "i en enda klass så att faktabankens
// artiklar och API:t inte glider isär"). Faktabankens process-artiklar citerar varje mall
// ordagrant och ReleaseFactbankSyncTest jämför dem. Ändra en mall: ändra artikeln i samma commit.
final class ReleaseNext
{
    public const TEMPLATES = [
        'releases_disabled' => 'Släpp är avstängda just nu. Vänta tills Erik har slagit på dem igen.',
        'no_targets' => 'Spelet har inga produktionsmål. Be Erik sätta dem med set_release_targets.',
        'no_demo_build' => 'Det finns inget lyckat Demo-bygge ännu. Pusha till main och följ get_provisioning_status tills demo.state är live.',
        'setup_open' => 'Väntar på ett engångssteg: {instruction}',
        'ready_for_test' => 'Commit {commit} är testad i Demo. Be om {stage} med promote_release(target: {target}, stage: {stage}).',
        'ready_for_production' => 'Commit {commit} är testad i {test_stage}. Be om produktion med promote_release(target: {target}, stage: {stage}, commit_sha: {commit}).',
        'production_not_open' => 'Commit {commit} är testad i {test_stage}. Produktion byggs först i etapp {etapp}; inget mer att göra nu.',
        'awaiting_approval' => 'Väntar på Erik: släppet {release} av commit {commit} ska godkännas i Filament.',
        'queued_for_build' => 'Släppet {release} av commit {commit} är registrerat, men bygget för {stage} byggs först i etapp {etapp}. Inget mer att göra nu.',
        'in_progress' => 'Släppet {release} av commit {commit} pågår ({status}). Fråga igen med get_release_status.',
        'succeeded' => 'Släppet {release} av commit {commit} är klart i {stage}. Inget mer att göra.',
        'failed' => 'Släppet {release} av commit {commit} misslyckades: {error} Åtgärda orsaken och begär ett nytt släpp med promote_release(target: {target}, stage: {stage}).',
        'cancelled' => 'Släppet {release} av commit {commit} avbröts. Väntar det på godkännande som inte kommer: fråga Erik. Annars begär ett nytt med promote_release(target: {target}, stage: {stage}).',
    ];

    /** @param array<string, string> $vars */
    public static function for(string $state, array $vars = []): string
    {
        $template = self::TEMPLATES[$state] ?? throw new \InvalidArgumentException("Unknown next state: {$state}");

        $text = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($vars, $state) {
            return $vars[$m[1]] ?? throw new \LogicException("Template {$state} needs variable {$m[1]}.");
        }, $template);

        return (string) $text;
    }
}
