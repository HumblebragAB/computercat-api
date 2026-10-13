<?php

namespace Tests\Feature\Glosis;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreeUsageInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
    }

    private function row(array $attrs): void
    {
        AiUsage::create($attrs + ['game_id' => $this->game->id, 'feature' => 'tts', 'environment' => 'Production', 'units' => 1, 'est_cost_usd' => '0.000200', 'tier' => 'free']);
    }

    private function key(): AppAttestKey
    {
        return AppAttestKey::create(['game_id' => $this->game->id, 'key_id' => base64_encode(random_bytes(32)), 'public_key' => 'x', 'environment' => 'production']);
    }

    public function test_gront_nar_allt_stammer(): void
    {
        $this->row(['app_attest_key_id' => $this->key()->id]);
        $this->artisan('glosis:check-free-usage')->assertExitCode(0);
    }

    public function test_gratisrad_utan_nyckel_larmar(): void
    {
        $this->row(['app_attest_key_id' => null]);
        $this->artisan('glosis:check-free-usage')->expectsOutputToContain('utan nyckel')->assertExitCode(1);
    }

    public function test_fler_an_100_ord_per_nyckel_larmar(): void
    {
        $id = $this->key()->id;
        for ($i = 0; $i < 101; $i++) {
            $this->row(['app_attest_key_id' => $id]);
        }
        $this->artisan('glosis:check-free-usage')->expectsOutputToContain('per nyckel')->assertExitCode(1);
    }

    public function test_en_enda_gratisskanning_larmar(): void
    {
        // Fotot kräver Guld (beslut 2026-10-13): också en gratisskanning med nyckel är ett fel.
        $this->row(['feature' => 'scan', 'app_attest_key_id' => $this->key()->id]);
        $this->artisan('glosis:check-free-usage')->expectsOutputToContain('1 gratisskanningar, fotot kräver Guld')->assertExitCode(1);
    }

    public function test_guldskanning_larmar_inte(): void
    {
        $this->row(['feature' => 'scan', 'tier' => 'guld']);
        $this->artisan('glosis:check-free-usage')->assertExitCode(0);
    }

    public function test_over_delbudgeten_larmar(): void
    {
        $this->game->update(['settings' => ['free' => ['monthly_budget_usd' => 0.0001]]]);
        $this->row(['app_attest_key_id' => $this->key()->id]);
        $this->artisan('glosis:check-free-usage')->expectsOutputToContain('delbudgeten')->assertExitCode(1);
    }

    public function test_gallring_raknar_forst_och_raderar_bara_gamla(): void
    {
        $old = $this->key();
        $old->forceFill(['last_used_at' => now()->subMonths(14)])->save();
        $fresh = $this->key();
        $fresh->forceFill(['last_used_at' => now()])->save();

        $this->artisan('glosis:prune-attest-keys --months=13 --dry-run')->expectsOutputToContain('1 nyckel')->assertExitCode(0);
        $this->assertSame(2, AppAttestKey::count());
        $this->artisan('glosis:prune-attest-keys --months=13')->assertExitCode(0);
        $this->assertSame([$fresh->id], AppAttestKey::pluck('id')->all());
    }

    public function test_cachegallring_tar_bara_utgangna_glosisrader(): void
    {
        config(['cache.default' => 'database', 'cache.prefix' => 'app-cache-']);
        $past = now()->subHour()->getTimestamp();
        $future = now()->addHour()->getTimestamp();
        $rows = [
            ['app-cache-glosis-attest-used:a', $past],      // går
            ['app-cache-glosis-tts:free-day:1:2026-10-12', $past], // går
            ['app-cache-glosis-scan:free:x:2026-W42:slot:0', $past], // rest av gratisfotot, går
            ['app-cache-glosis-scan:free:y:2026-W42:slot:0', $future], // giltig, kvar
            ['app-cache-other-thing', $past],               // annat prefix, kvar
            ['other-cache-glosis-tts:x', $past],            // annan app, kvar
            ['app-cache-glosisXtts:x', $past],              // nästan-prefix, kvar
            ['app-cache-glosis-unknown:x', $past],          // okänt Glosis-prefix, kvar
            ['app-cache-glosis-tts-audio:x', $past],        // bara Limit::by-nyckel, kvar
        ];
        foreach ($rows as [$key, $exp]) {
            DB::table('cache')->insert(['key' => $key, 'value' => 's:1:"x";', 'expiration' => $exp]);
        }

        $this->artisan('glosis:prune-cache --dry-run')->expectsOutputToContain('3 utgångna')->assertExitCode(0);
        $this->assertSame(9, DB::table('cache')->count());

        $this->artisan('glosis:prune-cache')->assertExitCode(0);
        $this->assertEqualsCanonicalizing(
            ['app-cache-glosis-scan:free:y:2026-W42:slot:0', 'app-cache-other-thing', 'other-cache-glosis-tts:x', 'app-cache-glosisXtts:x', 'app-cache-glosis-unknown:x', 'app-cache-glosis-tts-audio:x'],
            DB::table('cache')->pluck('key')->all(),
        );
    }
}
