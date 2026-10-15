<?php

namespace App\Releases;

// ALLA `next`-texter finns här och bara här (spec: "i en enda klass så att faktabankens
// artiklar och API:t inte glider isär"). Faktabankens process-artiklar citerar varje mall
// ordagrant och ReleaseFactbankSyncTest jämför dem. Ändra en mall: ändra artikeln i samma commit.
final class ReleaseNext
{
    // "Skicka till TestFlight" (Eriks beslut 2026-10-15). Ordagrant i process-slappflode och i CLAUDE.md
    // för varje byggguideversion som stöder iOS; ReleaseFactbankSyncTest jämför. Ändra här, där och i
    // byggguiden i samma commit (en låst version ändras aldrig: då krävs en ny version).
    public const TESTFLIGHT_FLOW = 'Skicka till TestFlight (kräver byggguide v3 eller senare i spelet, den med iOS-stöd). En push utan höjd version bygger aldrig appen; bara promote_release efter en versionshöjning gör det. 1. Höj version i package.json (MAJOR.MINOR.PATCH): högre än den senast släppta till TestFlight och inte använd förut. 2. Kör npm run check, committa och pusha till main. 3. Vänta tills Demo-bygget för commiten är grönt (get_provisioning_status visar demo.state live). 4. Anropa promote_release(target: app_store, stage: testflight, commit_sha: <commiten>). 5. Följ get_release_status tills släppet är klart. Computercat sätter taggen v<version>, väljer byggnummer, bygger appen och lägger den i TestFlight-gruppen ComputerCat intern.';

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
        'ready_for_testflight' => 'Commit {commit} är testad i Demo. Senast släppta version till TestFlight: {last_version}. {flow}',
        'starting' => 'Släppet {release} (version {version}, bygge {build}) av commit {commit} startar. Fråga igen med get_release_status om en minut.',
        'building_testflight' => 'Släppet {release} (version {version}, bygge {build}) pågår: {phase}. Fråga igen med get_release_status om några minuter.',
        'succeeded_testflight' => 'Släppet {release} (version {version}, bygge {build}) finns i TestFlight för gruppen ComputerCat intern. Installera det med TestFlight-appen. För en ny version gäller flödet nedan. {flow}',
        'failed_testflight' => 'Släppet {release} (version {version}) av commit {commit} misslyckades: {error} Samma commit kan skickas igen med promote_release(target: app_store, stage: testflight, commit_sha: {commit}). Ändrar du koden: {flow}',
    ];

    /** @param array<string, string> $vars */
    public static function for(string $state, array $vars = []): string
    {
        $vars += ['flow' => self::TESTFLIGHT_FLOW];
        $template = self::TEMPLATES[$state] ?? throw new \InvalidArgumentException("Unknown next state: {$state}");

        $text = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($vars, $state) {
            return $vars[$m[1]] ?? throw new \LogicException("Template {$state} needs variable {$m[1]}.");
        }, $template);

        return (string) $text;
    }
}
