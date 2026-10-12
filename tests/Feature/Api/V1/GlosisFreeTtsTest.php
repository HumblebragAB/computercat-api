<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\StudioVoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Glosis\FreeGateTest as G;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class GlosisFreeTtsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/games/glosis/tts/prepare';

    private const VOICE = 'TestVoice123';

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    private int $count = 0;

    /** @var list<HttpRequest> */
    private array $elevenLabs = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        config(['app.url' => 'https://api.computercat.co']);
        Storage::fake(StudioVoice::DISK);
        Http::preventStrayRequests();
        Http::fake(['api.elevenlabs.io/*' => function (HttpRequest $request) {
            $this->elevenLabs[] = $request;

            return Http::response("\xFF\xFB\x90\x64ljud", 200, ['Content-Type' => 'audio/mpeg']);
        }]);
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'elevenlabs' => ['api_key' => Crypt::encryptString('xi-test'), 'voice_id' => self::VOICE],
            'free' => ['enabled' => true], 'app_attest' => ['team_id' => GlosisAttestTest::TEAM],
        ])]);
        [$this->key, $this->priv] = G::registeredKey($this->game, self::$fake);
    }

    private function credential(array $fields): array
    {
        return G::credential($this->key, $this->priv, 'tts', $fields, ++$this->count);
    }

    private function free(array $words, array $extra = [])
    {
        return $this->postJson(self::URL, ['attest' => $this->credential(['speed' => 'normal', 'voice' => 'female', 'words' => $words] + $extra)]);
    }

    public function test_gratis_far_studiorost_och_bokfors_som_gratis(): void
    {
        $this->free(['dog'])->assertOk()->assertJsonStructure(['urls' => ['dog'], 'skipped', 'voice_used']);
        $row = AiUsage::sole();
        $this->assertSame('free', $row->tier);
        $this->assertSame($this->key->id, $row->app_attest_key_id);
        $this->assertSame('Production', $row->environment);
    }

    public function test_falten_lases_bara_ur_signerad_client_data_aldrig_ur_kroppen(): void
    {
        // Kroppen försöker byta ord, takt, röst och språk utanför signaturen.
        $res = $this->postJson(self::URL, [
            'attest' => $this->credential(['speed' => 'normal', 'voice' => 'female', 'words' => ['dog']]),
            'words' => ['cat', 'horse'], 'speed' => 'slow', 'voice' => 'male', 'language' => 'de',
        ])->assertOk();

        $this->assertSame(['dog'], array_keys($res->json('urls')));
        $this->assertSame('female', $res->json('voice_used'));
        $this->assertCount(1, $this->elevenLabs);
        $sent = $this->elevenLabs[0]->data();
        $this->assertSame('dog', $sent['text']);
        $this->assertSame('en', $sent['language_code']);
        $this->assertSame(1.0, $sent['voice_settings']['speed']);
        $this->assertStringContainsString(self::VOICE, $this->elevenLabs[0]->url());
        Storage::disk(StudioVoice::DISK)->assertExists(StudioVoice::hash('dog', 'normal', self::VOICE).'.mp3');
        $this->assertSame(1, AiUsage::count());
    }

    public function test_ogiltiga_falt_i_kroppen_spelar_ingen_roll_och_saknade_i_client_data_ger_422(): void
    {
        // Kroppen har giltiga fält, men clientData saknar speed och words: ska avvisas.
        $this->postJson(self::URL, [
            'attest' => $this->credential(['voice' => 'female']),
            'words' => ['dog'], 'speed' => 'normal',
        ])->assertStatus(422)->assertJson(['error' => 'invalid_request']);
        Http::assertNothingSent();
    }

    public function test_cachade_ord_raknas_inte_och_100_nya_per_nyckel_och_dygn(): void
    {
        // Bara bokstäver: 0 → "orda", 12 → "ordbc" osv.
        $word = fn (int $i) => 'ord'.strtr((string) $i, '0123456789', 'abcdefghij');
        for ($batch = 0; $batch < 3; $batch++) {
            $this->free(array_map($word, range($batch * 40, $batch * 40 + 39)))->assertOk();
        }
        // 120 olika ord: 100 genererade, 20 nekade med limit
        $this->assertSame(100, AiUsage::where('tier', 'free')->count());
        // Cachat ord: fungerar trots att gränsen nåtts, och bokförs inte.
        $this->free([$word(0)])->assertOk()->assertJsonPath('skipped', []);
        $this->assertSame(100, AiUsage::where('tier', 'free')->count());
    }

    public function test_globalt_gratistak_paverkar_inte_guld_raknaren(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => true, 'tts_daily_new_limit' => 1]])]);
        $res = $this->free(['dog', 'cat'])->assertOk();
        $this->assertSame([['word' => 'cat', 'reason' => 'limit']], $res->json('skipped'));
        $this->assertSame(0, RateLimiter::attempts('glosis-tts:day:'.$this->game->id.':'.now('Europe/Stockholm')->format('Y-m-d')));
    }

    public function test_bade_proof_och_attest_ger_422(): void
    {
        $this->postJson(self::URL, ['proof' => ['platform' => 'ios', 'jws' => 'x'], 'attest' => $this->credential(['speed' => 'normal', 'words' => ['dog']])])
            ->assertStatus(422)->assertJson(['error' => 'invalid_request']);
        Http::assertNothingSent();
    }

    public function test_ogiltiga_ord_i_client_data_ger_422_utan_anrop(): void
    {
        $this->free([str_repeat('x', 2000)])->assertStatus(422)->assertJson(['error' => 'invalid_request']);
        Http::assertNothingSent();
    }

    public function test_avstangd_flagga_ger_503(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->free(['dog'])->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
        Http::assertNothingSent();
    }

    public function test_okand_nyckel_ger_401(): void
    {
        $this->key->delete();
        $this->free(['dog'])->assertStatus(401)->assertJson(['error' => 'unknown_key']);
    }

    public function test_omspelad_forfragan_avvisas(): void
    {
        $credential = $this->credential(['speed' => 'normal', 'words' => ['dog']]);
        $this->postJson(self::URL, ['attest' => $credential])->assertOk();
        $this->postJson(self::URL, ['attest' => $credential])->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
    }
}
