<?php

namespace App\Releases;

use App\Models\Game;
use App\Models\ReleaseSetupStep;
use Illuminate\Support\Collection;

// Engångssteg per spel och butik (spec Del 1). Skapas när ett mål läggs till.
// play_first_aab saknas medvetet: det skapas först om etapp 3:s spike bekräftar kravet (R9).
final class SetupSteps
{
    /** @var array<string, array<string, string>> */
    public const DEFINITIONS = [
        'web' => [
            'web_public_address' => 'Erik sätter spelets publika webbadress (domänen) för produktionswebben.',
        ],
        'app_store' => [
            'appstore_bundle_id' => 'Computercat registrerar bundle-id {bundle_id} i App Store Connect via API.',
            'appstore_profile' => 'Computercat skapar App Store-profilen för {bundle_id} via API.',
            'appstore_create_app' => 'Erik skapar appen i App Store Connect (Appar, +, Ny app): plattform iOS, namn {name}, primärspråk svenska, bundle-id {bundle_id}, SKU {sku}, användaråtkomst full åtkomst. Är namnet upptaget väljer Erik ett annat. Computercat ser själv när appen finns.',
            'appstore_testflight_group' => 'Computercat skapar den interna TestFlight-gruppen ComputerCat intern och lägger till testarna via API.',
            'appstore_privacy_and_age' => 'Erik fyller i appens integritetsuppgifter och åldersgräns i App Store Connect. Krävs före App Store, inte före TestFlight.',
        ],
        'play_store' => [
            'play_create_app' => 'Erik skapar appen i Play Console med spelets paketnamn.',
            'play_store_listing' => 'Erik fyller i innehållsklassning, dataskydd, målgrupp och butikstext i Play Console.',
            'play_app_signing' => 'Erik aktiverar Play App Signing för appen i Play Console.',
        ],
    ];

    /** Steg som Computercat själv gör (etapp 2). De kan inte bockas av för hand i Filament. */
    public const AUTOMATIC = ['appstore_bundle_id', 'appstore_profile', 'appstore_testflight_group'];

    /** Eriks steg som Computercat kontrollerar mot Apple innan de räknas som klara (R8). */
    public const VERIFIED = ['appstore_create_app'];

    /** Steg som bara stoppar vissa steg (R6). Ett steg som saknas här stoppar alla målets steg. */
    public const BLOCKS = ['appstore_privacy_and_age' => ['app_store']];

    public static function render(string $template, Game $game): string
    {
        return strtr($template, [
            '{bundle_id}' => (string) ($game->bundle_id ?? '(saknas)'),
            '{name}' => (string) $game->name,
            '{sku}' => (string) $game->slug,
        ]);
    }

    public static function blocks(string $key, string $stage): bool
    {
        return ! isset(self::BLOCKS[$key]) || in_array($stage, self::BLOCKS[$key], true);
    }

    // Första öppna steget som stoppar steget, i definitionsordning (inte radordning: steg som
    // lagts till i etapp 2 har högre id än 1a:s).
    public static function openFor(Game $game, string $target, string $stage): ?ReleaseSetupStep
    {
        return self::open($game, $target)->first(fn (ReleaseSetupStep $s) => self::blocks($s->key, $stage));
    }

    /** @return Collection<int, ReleaseSetupStep> målets öppna steg i definitionsordning */
    public static function open(Game $game, string $target): Collection
    {
        $order = array_flip(array_keys(self::DEFINITIONS[$target] ?? []));

        return ReleaseSetupStep::where('game_id', $game->id)->where('target', $target)->where('status', ReleaseSetupStep::OPEN)->orderBy('id')->get()
            ->sortBy(fn (ReleaseSetupStep $s) => $order[$s->key] ?? PHP_INT_MAX)
            ->values();
    }

    /** Skapar saknade steg och uppdaterar texten på öppna steg. Klara steg lämnas orörda; inget raderas. */
    public static function sync(Game $game): void
    {
        foreach ($game->releaseTargets() as $target) {
            foreach (self::DEFINITIONS[$target] ?? [] as $key => $template) {
                $instruction = self::render($template, $game);
                $step = ReleaseSetupStep::firstOrCreate(
                    ['game_id' => $game->id, 'target' => $target, 'key' => $key],
                    ['status' => ReleaseSetupStep::OPEN, 'instruction' => $instruction],
                );
                if ($step->status === ReleaseSetupStep::OPEN && $step->instruction !== $instruction) {
                    $step->update(['instruction' => $instruction]);
                }
            }
        }
    }
}
