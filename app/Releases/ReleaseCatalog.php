<?php

namespace App\Releases;

// Ren data: vilka mål och steg som finns (spec Del 1, "Steg per mål").
final class ReleaseCatalog
{
    public const TARGETS = ['web', 'app_store', 'play_store'];

    /** Steg som kan begäras med promote_release. `demo` är inget släpp: det är ett push-bygge. */
    public const PROMOTABLE_STAGES = ['testflight', 'play_internal', 'web_production', 'app_store', 'play_production'];

    public const STAGES = [
        'web' => ['test' => 'demo', 'production' => 'web_production'],
        'app_store' => ['test' => 'testflight', 'production' => 'app_store'],
        'play_store' => ['test' => 'play_internal', 'production' => 'play_production'],
    ];

    /** I vilken etapp stegets bygge kommer (R1: etapp 1a bygger inget). */
    public const BUILD_ETAPP = [
        'web_production' => '1b',
        'testflight' => '2',
        'app_store' => '2 (App Store-delen)',
        'play_internal' => '3',
        'play_production' => '3',
    ];

    /** Steg som Computercat själv bygger i den här etappen (etapp 2 del 1: bara TestFlight). */
    public const BUILT_BY_COMPUTERCAT = ['testflight'];

    public static function isBuiltByComputercat(string $stage): bool
    {
        return in_array($stage, self::BUILT_BY_COMPUTERCAT, true);
    }

    public static function testStage(string $target): string
    {
        return self::STAGES[$target]['test'] ?? throw new \InvalidArgumentException("Unknown target: {$target}");
    }

    public static function productionStage(string $target): string
    {
        return self::STAGES[$target]['production'] ?? throw new \InvalidArgumentException("Unknown target: {$target}");
    }

    public static function isPromotable(string $target, string $stage): bool
    {
        return in_array($stage, self::PROMOTABLE_STAGES, true)
            && in_array($stage, self::STAGES[$target] ?? [], true);
    }

    public static function isProduction(string $target, string $stage): bool
    {
        return isset(self::STAGES[$target]) && self::STAGES[$target]['production'] === $stage;
    }

    /** Är stegets bygge öppet för begäran? Styrs av config('releases.buildable_stages') (R1). */
    public static function isBuildable(string $stage): bool
    {
        return in_array($stage, (array) config('releases.buildable_stages'), true);
    }

    /** Fullständigt SHA i gemener. \z, inte $, så att en avslutande radbrytning inte släpps igenom. */
    public static function isCommit(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}\z/', $value) === 1;
    }
}
