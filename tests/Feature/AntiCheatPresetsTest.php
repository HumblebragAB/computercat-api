<?php

namespace Tests\Feature;

use App\Filament\Resources\GameResource;
use App\Filament\Resources\GameResource\Pages\CreateGame;
use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\LeaderboardType;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\IntegrationGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Förvalen i Filament mot AntiCheatService. Bevisets t är alltid millisekunder
// (createProof i byggguidens proof.ts, elapsedMs); poängen kan vara ms, sekunder eller poäng.
class AntiCheatPresetsTest extends TestCase
{
    use RefreshDatabase;

    private function type(string $preset): LeaderboardType
    {
        $game = Game::create([
            'slug' => 'ac-'.str_replace('_', '-', $preset).'-'.Game::count(), 'name' => 'AC', 'is_active' => true,
            'settings' => ['anti_cheat_preset' => $preset, 'anti_cheat' => GameResource::ANTI_CHEAT_PRESETS[$preset]['config']],
        ]);

        return LeaderboardType::create([
            'game_id' => $game->id, 'slug' => 'daily', 'name' => 'Daily', 'sort_direction' => 'desc',
            'score_label' => 'x', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true,
        ]);
    }

    private function proof(int $elapsedMs, int $moves = 10): array
    {
        $sign = (new \ReflectionMethod(AntiCheatService::class, 'fnv1aSignature'))->getClosure(new AntiCheatService);

        return ['proof' => ['pid' => 'r1', 'mc' => $moves, 'gh' => 'abc', 't' => $elapsedMs, 'sig' => $sign('r1', $moves, 'abc', $elapsedMs)]];
    }

    private function check(string $preset, int $score, int $elapsedMs): array
    {
        return (new AntiCheatService)->check($this->type($preset), $score, $this->proof($elapsedMs));
    }

    public function test_points_with_proof_accepts_points_whatever_the_play_time(): void
    {
        $this->assertTrue($this->check('points_with_proof', 42, 61_234)['passed']);
        $this->assertTrue($this->check('points_with_proof', 900_000, 3_000)['passed']);
    }

    public function test_points_preset_accepts_an_optional_proof(): void
    {
        $this->assertTrue($this->check('points', 42, 61_234)['passed']);
    }

    public function test_time_seconds_compares_the_proof_in_seconds(): void
    {
        $this->assertTrue($this->check('time_seconds', 61, 61_234)['passed']);
        $this->assertTrue($this->check('time_seconds', 63, 61_234)['passed']);
        $this->assertSame('Proof time does not match score.', $this->check('time_seconds', 70, 61_234)['reason']);
    }

    public function test_time_ms_still_compares_milliseconds(): void
    {
        $this->assertTrue($this->check('time_ms', 45_000, 45_400)['passed']);
        $this->assertSame('Proof time does not match score.', $this->check('time_ms', 50_000, 45_000)['reason']);
    }

    public function test_bad_signature_is_still_rejected_for_points(): void
    {
        $proof = $this->proof(61_234);
        $proof['proof']['sig'] = 'fel';

        $this->assertSame('Proof signature mismatch.', (new AntiCheatService)->check($this->type('points_with_proof'), 42, $proof)['reason']);
    }

    // Byte av förval i Filament får inte lämna nycklar från det förra kvar (dolda fält som
    // proof_time_tolerance och score_unit har inget eget fält i panelen).
    public function test_every_preset_sets_every_preset_key(): void
    {
        $keys = collect(GameResource::ANTI_CHEAT_PRESETS)->flatMap(fn ($p) => array_keys($p['config']))->unique()->sort()->values()->all();
        foreach (GameResource::ANTI_CHEAT_PRESETS as $name => $preset) {
            $this->assertSame($keys, collect(array_keys(GameResource::presetSettings($name)))->sort()->values()->all(), $name);
        }
        $this->assertNull(GameResource::presetSettings('points')['score_unit']);
        $this->assertSame(0, GameResource::presetSettings('points_with_proof')['proof_time_tolerance']);
    }

    // Spelets Claude läser reglerna i integrationsmanualen; de ska säga samma sak som servern.
    public function test_integration_guide_states_how_t_is_compared(): void
    {
        $manual = fn (string $preset) => IntegrationGuide::manual($this->type($preset)->game, array_keys(IntegrationGuide::FEATURES));

        $this->assertStringContainsString('Elapsed time in milliseconds', $manual('time_ms'));
        $this->assertStringContainsString('`t` must match the score within `1000` ms.', $manual('time_ms'));
        $this->assertStringContainsString('`t` must match the score within `2` seconds (the server divides `t` by 1000).', $manual('time_seconds'));
        $this->assertStringContainsString('`t` is not compared with the score', $manual('points_with_proof'));
    }

    // Panelen är enda skrivvägen för anti_cheat. Förvalets dolda nycklar ska nå databasen
    // och överleva en omsparning (formuläret ersätter hela settings.anti_cheat vid save).
    public function test_panel_saves_and_keeps_the_hidden_preset_keys(): void
    {
        $this->actingAs(User::factory()->staff()->create());

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'acpanel', 'name' => 'AC', 'settings.anti_cheat_preset' => 'points_with_proof'])
            ->call('create')->assertHasNoFormErrors();
        $game = Game::where('slug', 'acpanel')->firstOrFail();
        $this->assertSame(0, $game->settings['anti_cheat']['proof_time_tolerance']);
        $this->assertSame('fnv1a', $game->settings['anti_cheat']['proof_algorithm']);

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['settings.anti_cheat_preset' => 'time_seconds'])
            ->call('save')->assertHasNoFormErrors();
        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['name' => 'AC2'])
            ->call('save')->assertHasNoFormErrors();
        $settings = $game->fresh()->settings['anti_cheat'];
        $this->assertSame('seconds', $settings['score_unit']);
        $this->assertSame(2, (int) $settings['proof_time_tolerance']);

        $type = LeaderboardType::create([
            'game_id' => $game->id, 'slug' => 'daily', 'name' => 'Daily', 'sort_direction' => 'asc',
            'score_label' => 's', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true,
        ]);
        $this->assertTrue((new AntiCheatService)->check($type, 61, $this->proof(61_234))['passed']);
    }
}
