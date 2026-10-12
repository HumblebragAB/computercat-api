<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\GuldStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Invarianterna för Glosis gratisnivå (planen 2026-10-12). Larmar i loggen och med exit 1.
 *
 * Taken läses som de är när kommandot körs. Sänks ett tak i Filament mitt på dagen kan dagens
 * redan bokförda rader ligga över det nya taket och ge ett falskt larm; kontrollera då mot
 * taket som gällde. Höjs ett tak blir kontrollen bara snällare.
 */
class CheckGlosisFreeUsage extends Command
{
    protected $signature = 'glosis:check-free-usage';

    protected $description = 'Kontrollerar att gratisnivåns användning håller sig inom gränserna och är kopplad till nycklar';

    public function handle(): int
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $settings = GlosisSettings::for($game);
        $now = CarbonImmutable::now(GuldStatus::TIME_ZONE);
        $tz = config('app.timezone');
        $problems = [];

        foreach ([$now->startOfDay(), $now->subDay()->startOfDay()] as $day) {
            $from = $day->setTimezone($tz);
            $to = $day->addDay()->startOfDay()->setTimezone($tz);
            $free = fn () => AiUsage::where('game_id', $game->id)->where('tier', AiUsage::TIER_FREE)->where('created_at', '>=', $from)->where('created_at', '<', $to);
            $d = $day->format('Y-m-d');

            if (($n = $free()->whereNull('app_attest_key_id')->count()) > 0) {
                $problems[] = "{$d}: {$n} gratisrader utan nyckel";
            }
            if (($n = $free()->where('feature', AiUsage::FEATURE_TTS)->count()) > $settings->freeTtsDailyNewLimit()) {
                $problems[] = "{$d}: {$n} nya gratisord, taket är {$settings->freeTtsDailyNewLimit()}";
            }
            $perKey = $free()->where('feature', AiUsage::FEATURE_TTS)->whereNotNull('app_attest_key_id')
                ->selectRaw('app_attest_key_id, count(*) as n')->groupBy('app_attest_key_id')
                ->havingRaw('count(*) > ?', [GlosisSettings::TTS_DAILY_NEW_PER_KEY])->get()->count();
            if ($perKey > 0) {
                $problems[] = "{$d}: {$perKey} nycklar över ".GlosisSettings::TTS_DAILY_NEW_PER_KEY.' nya ord per nyckel';
            }
            if (($n = $free()->where('feature', AiUsage::FEATURE_SCAN)->count()) > $settings->freeScanDailyLimit()) {
                $problems[] = "{$d}: {$n} gratisskanningar, taket är {$settings->freeScanDailyLimit()}";
            }
        }
        $spent = AiBudget::spentMicroUsd($game, $now, AiUsage::TIER_FREE);
        if ($spent > $settings->freeMonthlyBudgetMicroUsd()) {
            $problems[] = 'gratisnivåns månadskostnad '.($spent / 1_000_000).' USD är över delbudgeten';
        }

        foreach ($problems as $p) {
            $this->error($p);
        }
        if ($problems !== []) {
            Log::error('Glosis: gratisnivåns invarianter bryts', ['problems' => $problems]);

            return self::FAILURE;
        }
        $this->info('Gratisnivåns invarianter håller.');

        return self::SUCCESS;
    }
}
