<?php

namespace Tests\Feature\Glosis;

use App\Models\AiUsage;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\GlosisCaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiBudgetFreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gratis_stoppas_av_delbudgeten_men_guld_gar_igenom(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 10], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);

        $this->assertNotNull($r = $budget->reserve($game, 900_000, AiUsage::FEATURE_SCAN, GlosisCaller::FREE));
        $budget->release($r);
        $this->assertNull($budget->reserve($game, 1_100_000, AiUsage::FEATURE_SCAN, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 1_100_000, AiUsage::FEATURE_SCAN));
    }

    public function test_avslag_i_delbudgeten_lamnar_inget_reserverat_i_den_globala(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['ai' => ['monthly_budget_usd' => 2], 'free' => ['monthly_budget_usd' => 1]])]);
        $budget = app(AiBudget::class);
        $this->assertNull($budget->reserve($game, 1_500_000, AiUsage::FEATURE_SCAN, GlosisCaller::FREE));
        $this->assertNotNull($budget->reserve($game, 2_000_000, AiUsage::FEATURE_SCAN));
    }
}
