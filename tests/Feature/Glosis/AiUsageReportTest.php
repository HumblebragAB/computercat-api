<?php

namespace Tests\Feature\Glosis;

use App\Filament\Pages\AiUsageOverview;
use App\Models\AiUsage;
use App\Models\Game;
use App\Models\User;
use App\Services\Glosis\AiUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AiUsageReportTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        // Torsdag 15 oktober 2026 12:00 i Stockholm. Veckan började måndag 12/10.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-15 12:00', 'Europe/Stockholm')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usage(string $feature, string $stockholmTime, string $cost, ?Game $game = null): void
    {
        $row = AiUsage::create(['game_id' => ($game ?? $this->game)->id, 'feature' => $feature, 'environment' => 'Production', 'est_cost_usd' => $cost]);
        $row->forceFill(['created_at' => CarbonImmutable::parse($stockholmTime, 'Europe/Stockholm')->utc()])->save();
    }

    public function test_sums_per_day_week_and_month_in_stockholm_time(): void
    {
        $this->usage('scan', '2026-10-15 08:00', '0.006000');
        $this->usage('scan', '2026-10-15 00:30', '0.004000');   // 22:30 UTC dagen innan
        $this->usage('tts', '2026-10-14 10:00', '0.000200');
        $this->usage('scan', '2026-10-11 23:59', '0.010000');   // söndag: förra veckan
        $this->usage('tts', '2026-10-01 00:10', '0.000400');    // månadens första, 13 dagar före spannet? nej: 14 dagar = 2/10–15/10
        $this->usage('scan', '2026-09-30 23:50', '1.000000');   // förra månaden, utanför 14 dagar
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
        $this->usage('scan', '2026-10-15 09:00', '5.000000', $tocco);

        $report = AiUsageReport::build($this->game, now());

        $this->assertCount(14, $report['days']);
        $this->assertSame('2026-10-15', $report['days'][0]['date']);
        $this->assertSame('2026-10-02', $report['days'][13]['date']);
        $this->assertSame(['count' => 2, 'micro_usd' => 10_000], $report['days'][0]['features']['scan']);
        $this->assertSame(['count' => 1, 'micro_usd' => 200], $report['days'][1]['features']['tts']);
        $this->assertSame(['count' => 1, 'micro_usd' => 10_000], $report['days'][4]['features']['scan']); // 11/10

        $this->assertSame(['count' => 2, 'micro_usd' => 10_000], $report['week']['scan']);
        $this->assertSame(['count' => 1, 'micro_usd' => 200], $report['week']['tts']);
        $this->assertSame(['count' => 3, 'micro_usd' => 20_000], $report['month']['scan']);
        $this->assertSame(['count' => 2, 'micro_usd' => 600], $report['month']['tts']);
        $this->assertSame(20_600, $report['month_total_micro_usd']);
        $this->assertSame(50_000_000, $report['budget_micro_usd']);

        // Invariant: dagarna i månaden och spannet summerar till månadens siffra
        // för de dagar som ligger i båda.
        $daysInMonth = collect($report['days'])->filter(fn ($d) => str_starts_with($d['date'], '2026-10'));
        $this->assertSame(
            $report['month']['scan']['micro_usd'],
            $daysInMonth->sum(fn ($d) => $d['features']['scan']['micro_usd'])
        );
    }

    public function test_page_renders_for_admin(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $this->usage('scan', '2026-10-15 08:00', '1.250000');

        Livewire::test(AiUsageOverview::class)
            ->assertOk()
            ->assertSee('$1.25 av $50.00')
            ->assertSee('Fota läxan')
            ->assertSee('Studioröst');

        $this->get('/admin/ai-usage')->assertOk();
    }
}
