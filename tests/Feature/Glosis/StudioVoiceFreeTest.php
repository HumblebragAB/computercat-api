<?php

namespace Tests\Feature\Glosis;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\GlosisCaller;
use App\Services\Glosis\StudioVoice;
use App\Services\Glosis\VerifiedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** StudioVoice med gratisnivåns anropare: egna räknare, egen delbudget, tier i ai_usage. */
class StudioVoiceFreeTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private AppAttestKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'elevenlabs' => ['api_key' => Crypt::encryptString('xi-test'), 'voice_id' => 'TestVoice123'],
        ])]);
        config(['app.url' => 'https://api.computercat.co']);
        Storage::fake(StudioVoice::DISK);
        Http::preventStrayRequests();
        Http::fake(['api.elevenlabs.io/*' => Http::response("\xFF\xFB\x90\x64ljud", 200, ['Content-Type' => 'audio/mpeg'])]);
        $this->key = AppAttestKey::create(['game_id' => $this->game->id, 'key_id' => base64_encode(str_repeat("\x01", 32)), 'public_key' => 'x', 'sign_count' => 1, 'environment' => 'production']);
    }

    private function day(): string
    {
        return CarbonImmutable::now('Europe/Stockholm')->format('Y-m-d');
    }

    private function free(string $ip = '1.2.3.4'): GlosisCaller
    {
        return GlosisCaller::free($this->key, $ip);
    }

    public function test_gratis_bokfors_som_free_med_nyckeln_och_rors_inte_guldraknaren(): void
    {
        $result = app(StudioVoice::class)->prepare($this->game, $this->free(), 'normal', ['dog'], 'en', 'female');

        $this->assertArrayHasKey('dog', $result['urls']);
        $usage = AiUsage::sole();
        $this->assertSame([AiUsage::TIER_FREE, $this->key->id, 'Production'], [$usage->tier, $usage->app_attest_key_id, $usage->environment]);
        $this->assertSame(0, RateLimiter::attempts('glosis-tts:day:'.$this->game->id.':'.$this->day()));
        $this->assertSame(1, RateLimiter::attempts('glosis-tts:free-day:'.$this->game->id.':'.$this->day()));
        $this->assertSame(1, RateLimiter::attempts('glosis-tts:'.$this->free()->limitId().':'.$this->day()));
        // Delräknaren följer ai_usage (invarianten i AiBudget).
        $this->assertSame(AiBudget::spentMicroUsd($this->game, now(), GlosisCaller::FREE), Cache::get(AiBudget::freeMonthKey($this->game, now())));
    }

    public function test_ip_granen_stoppar_gratis_men_inte_guld(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['tts_ip_daily_new_limit' => 1]])]);
        $voice = app(StudioVoice::class);

        $result = $voice->prepare($this->game, $this->free(), 'normal', ['dog', 'cat'], 'en', 'female');
        $this->assertSame([['word' => 'cat', 'reason' => 'limit']], $result['skipped']);

        $guld = GlosisCaller::guld(new VerifiedTransaction('2000000111111111', '2000000111111111', 'glosis_guld_2026_27', 'Production', null));
        $this->assertSame([], $voice->prepare($this->game, $guld, 'normal', ['cow'], 'en', 'female')['skipped']);
        $this->assertSame([AiUsage::TIER_FREE, AiUsage::TIER_GULD], AiUsage::orderBy('id')->pluck('tier')->all());
    }

    public function test_gratisnivans_globala_dygnsgrans_galler_over_nycklar(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['tts_daily_new_limit' => 1]])]);
        $other = AppAttestKey::create(['game_id' => $this->game->id, 'key_id' => base64_encode(str_repeat("\x02", 32)), 'public_key' => 'x', 'sign_count' => 1, 'environment' => 'production']);
        $voice = app(StudioVoice::class);

        $voice->prepare($this->game, $this->free(), 'normal', ['dog'], 'en', 'female');
        $result = $voice->prepare($this->game, GlosisCaller::free($other, '5.6.7.8'), 'normal', ['cat'], 'en', 'female');
        $this->assertSame([['word' => 'cat', 'reason' => 'limit']], $result['skipped']);
        // Avvisade anrop lämnar inget kvar i räknarna.
        $this->assertSame(0, RateLimiter::attempts('glosis-tts:'.GlosisCaller::free($other, null)->limitId().':'.$this->day()));
    }

    public function test_gratis_och_guld_raknare_ar_skilda_och_guldnyckeln_oforandrad(): void
    {
        $tx = new VerifiedTransaction('2000000111111111', '2000000111111111', 'p', 'Production', null);
        $this->assertSame('tx:'.hash('sha256', '2000000111111111'), GlosisCaller::guld($tx)->limitId());
        $this->assertSame('tx:'.hash('sha256', 'filament-preview'), GlosisCaller::admin()->limitId());
        $this->assertStringStartsWith('key:', $this->free()->limitId());
        $this->assertSame($this->key->logHash(), $this->free()->logHash());
    }
}
