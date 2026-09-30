<?php

namespace Tests\Feature\Api\V1;

use App\Mail\InterestConfirmation;
use App\Models\Game;
use App\Models\InterestSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InterestSignupTest extends TestCase
{
    use RefreshDatabase;

    private Game $glosis;

    protected function setUp(): void
    {
        parent::setUp();

        // Skapas av migreringen add_glosis_game.
        $this->glosis = Game::where('slug', 'glosis')->firstOrFail();
        Mail::fake();
    }

    private function anmal(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/games/glosis/interest', array_merge(
            ['email' => 'Anna@Example.com ', 'language' => 'de'],
            $data,
        ));
    }

    public function test_anmalan_sparas_obekraftad_och_bekraftelsemail_skickas(): void
    {
        $this->anmal()->assertStatus(202)->assertJsonStructure(['message']);

        $rad = InterestSignup::sole();
        $this->assertSame('anna@example.com', $rad->email);
        $this->assertSame('de', $rad->language);
        $this->assertNull($rad->confirmed_at);
        $this->assertNotNull($rad->confirmation_sent_at);
        Mail::assertSent(InterestConfirmation::class, fn ($m) => $m->hasTo('anna@example.com'));
    }

    public function test_lanken_i_mailet_bekraftar_och_avregistrerar(): void
    {
        $this->anmal();

        $token = null;
        Mail::assertSent(InterestConfirmation::class, function ($m) use (&$token) {
            $token = $m->token;

            return true;
        });

        // Token sparas bara som hash.
        $this->assertNotSame($token, InterestSignup::sole()->token_hash);

        $this->get("/api/v1/interest/{$token}/confirm")->assertOk()->assertSee('Tack, nu är du anmäld!');
        $this->assertNotNull(InterestSignup::sole()->confirmed_at);

        $this->get("/api/v1/interest/{$token}/unsubscribe")->assertOk()->assertSee('Du är avregistrerad');
        $this->assertSame(0, InterestSignup::count());
    }

    public function test_okand_token_ger_404(): void
    {
        $this->get('/api/v1/interest/finns-inte/confirm')->assertNotFound();
    }

    public function test_samma_adress_igen_ger_samma_svar_och_en_rad(): void
    {
        $this->anmal();
        $this->anmal(['language' => 'fr'])->assertStatus(202);

        $this->assertSame(1, InterestSignup::count());
        $this->assertSame('fr', InterestSignup::sole()->language);
    }

    public function test_bekraftad_adress_far_inget_nytt_mail(): void
    {
        $this->anmal();
        InterestSignup::query()->update(['confirmed_at' => now()]);
        Mail::fake();

        $this->anmal(['language' => 'es'])->assertStatus(202);

        Mail::assertNothingSent();
        $this->assertSame('es', InterestSignup::sole()->language);
    }

    public function test_validering(): void
    {
        $this->anmal(['email' => 'inte-en-adress'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->anmal(['language' => 'sv'])->assertStatus(422)->assertJsonValidationErrors('language');
        $this->assertSame(0, InterestSignup::count());
    }

    public function test_honungsfallan_sparar_inget(): void
    {
        $this->anmal(['website' => 'http://spam.example'])->assertStatus(422);

        $this->assertSame(0, InterestSignup::count());
        Mail::assertNothingSent();
    }

    public function test_okant_eller_inaktivt_spel_ger_404(): void
    {
        $this->postJson('/api/v1/games/finns-inte/interest', ['email' => 'a@b.se', 'language' => 'de'])->assertNotFound();
    }

    public function test_mailaren_log_sparar_utan_mail_och_loggar_varning(): void
    {
        config(['mail.default' => 'log']);
        Log::spy();

        $this->anmal()->assertStatus(202);

        $this->assertNull(InterestSignup::sole()->confirmation_sent_at);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'utan bekräftelsemail'));
    }

    public function test_hastighetsbegransning_per_adress(): void
    {
        foreach (range(1, 3) as $i) {
            $this->anmal()->assertStatus(202);
        }

        $this->anmal()->assertStatus(429);
    }

    public function test_gallringen_tar_bara_gamla_obekraftade(): void
    {
        $gammal = InterestSignup::create(['game_id' => $this->glosis->id, 'email' => 'gammal@x.se', 'language' => 'de', 'token_hash' => str_repeat('a', 64)]);
        $bekraftad = InterestSignup::create(['game_id' => $this->glosis->id, 'email' => 'bekraftad@x.se', 'language' => 'de', 'token_hash' => str_repeat('b', 64), 'confirmed_at' => now()]);
        $ny = InterestSignup::create(['game_id' => $this->glosis->id, 'email' => 'ny@x.se', 'language' => 'de', 'token_hash' => str_repeat('c', 64)]);
        InterestSignup::whereIn('id', [$gammal->id, $bekraftad->id])->update(['created_at' => now()->subDays(40)]);

        $this->artisan('interest:prune-unconfirmed', ['--days' => 30])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$bekraftad->id, $ny->id], InterestSignup::pluck('id')->all());
    }

    public function test_publika_spelsvaret_saknar_settings(): void
    {
        $this->glosis->update(['settings' => ['revenuecat' => ['webhook_secret' => 'hemligt']]]);

        $this->getJson('/api/v1/games')->assertOk()->assertJsonMissingPath('data.0.settings')->assertDontSee('hemligt');
        $this->getJson('/api/v1/games/glosis')->assertOk()->assertJsonMissingPath('data.settings')->assertDontSee('hemligt');
    }

    public function test_adminvyn_listar_anmalningar_for_admin(): void
    {
        $this->anmal();
        $admin = \App\Models\User::factory()->create(['email' => 'erik@humblebrag.se']);

        $this->actingAs($admin)->get('/admin/interest-signups')->assertOk()->assertSee('anna@example.com');
    }
}
