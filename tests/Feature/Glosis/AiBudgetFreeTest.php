<?php

namespace Tests\Feature\Glosis;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\GlosisCaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiBudgetFreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gratis_stoppas_av_delbudgeten_men_guld_gar_igenom(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 10], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);

        $this->assertNotNull($r = $budget->reserve($game, 900_000, AiUsage::FEATURE_TTS, GlosisCaller::FREE));
        $budget->release($r);
        $this->assertNull($budget->reserve($game, 1_100_000, AiUsage::FEATURE_TTS, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 1_100_000, AiUsage::FEATURE_TTS));
    }

    public function test_avslag_i_delbudgeten_lamnar_inget_reserverat_i_den_globala(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 2], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);
        $this->assertNull($budget->reserve($game, 1_500_000, AiUsage::FEATURE_TTS, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 2_000_000, AiUsage::FEATURE_TTS));
    }

    public function test_release_aterfor_delreservationen(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 10], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);

        $budget->release($budget->reserve($game, 900_000, AiUsage::FEATURE_TTS, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 900_000, AiUsage::FEATURE_TTS, GlosisCaller::FREE));
    }

    public function test_nivan_bokfors_fran_reservationen_inte_fran_anroparen(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $key = AppAttestKey::create(['game_id' => $game->id, 'key_id' => base64_encode(str_repeat("\x01", 32)), 'public_key' => 'x', 'sign_count' => 1, 'environment' => 'production']);
        $budget = app(AiBudget::class);

        // Guld-reservation med en gratisanropare: raden blir guld, utan nyckel, och delräknaren rörs inte.
        $budget->record($game, $budget->reserve($game, 1000, AiUsage::FEATURE_TTS), AiUsage::FEATURE_TTS, 'Production', 1000, [], GlosisCaller::free($key, '1.2.3.4'));
        $usage = AiUsage::sole();
        $this->assertSame([AiUsage::TIER_GULD, null], [$usage->tier, $usage->app_attest_key_id]);
        $this->assertNull(Cache::get(AiBudget::freeMonthKey($game, now())));

        // Gratisreservation: raden blir free med nyckeln, och delräknaren följer ai_usage.
        $budget->record($game, $budget->reserve($game, 1000, AiUsage::FEATURE_TTS, GlosisCaller::FREE), AiUsage::FEATURE_TTS, 'Production', 700, [], GlosisCaller::free($key, '1.2.3.4'));
        $free = AiUsage::where('tier', AiUsage::TIER_FREE)->sole();
        $this->assertSame($key->id, $free->app_attest_key_id);
        $this->assertSame(700, Cache::get(AiBudget::freeMonthKey($game, now())));
        $this->assertSame(AiBudget::spentMicroUsd($game, now()), Cache::get(AiBudget::monthKey($game, now())));
    }
}
