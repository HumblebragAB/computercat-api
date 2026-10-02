<?php

namespace Tests\Feature\Glosis;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\User;
use App\Services\Glosis\GlosisSettings;
use Database\Seeders\GlosisGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class GlosisSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_keeps_settings(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update([
            'is_active' => false,
            'settings' => ['site_url' => 'https://glosis.se', 'anthropic' => ['api_key' => Crypt::encryptString('nyckel')]],
        ]);

        $this->seed(GlosisGameSeeder::class);
        $this->seed(GlosisGameSeeder::class);

        $this->assertSame(1, Game::where('slug', 'glosis')->count());
        $game->refresh();
        $this->assertSame('Glosis', $game->name);
        $this->assertTrue($game->is_active);
        $this->assertSame('nyckel', Crypt::decryptString($game->settings['anthropic']['api_key']));
    }

    public function test_seeder_creates_the_game_when_missing(): void
    {
        Game::where('slug', 'glosis')->delete();

        $this->seed(GlosisGameSeeder::class);

        $this->assertTrue(Game::where('slug', 'glosis')->where('is_active', true)->where('name', 'Glosis')->exists());
    }

    public function test_filament_stores_anthropic_key_encrypted_and_never_shows_it(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $game = Game::where('slug', 'glosis')->firstOrFail();

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.anthropic.api_key' => null])
            ->fillForm(['settings.anthropic.api_key' => '  sk-ant-hemlig  '])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $game->refresh()->settings['anthropic']['api_key'];
        $this->assertNotSame('sk-ant-hemlig', $stored);
        $this->assertSame('sk-ant-hemlig', Crypt::decryptString($stored));

        // Laddas om: fältet är tomt, och sparas tomt behålls nyckeln.
        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.anthropic.api_key' => null])
            ->assertDontSee('sk-ant-hemlig')
            ->assertDontSee($stored)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-ant-hemlig', Crypt::decryptString($game->refresh()->settings['anthropic']['api_key']));
        // Nycklar utan fält i formuläret får inte försvinna när nyckeln sparas.
        $this->assertSame('https://glosis.se', $game->settings['site_url']);
    }

    public function test_filament_stores_google_play_service_account_encrypted_and_never_shows_it(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $json = \Tests\Support\FakeGooglePlay::serviceAccountJson();
        $keyLine = explode("\n", json_decode($json, true)['private_key'])[1];

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['settings.google_play.service_account_json' => '{"type":"authorized_user"}'])
            ->call('save')
            ->assertHasFormErrors(['settings.google_play.service_account_json']);
        $this->assertNull($game->refresh()->settings['google_play'] ?? null);

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.google_play.service_account_json' => null])
            ->fillForm(['settings.google_play.service_account_json' => "  {$json}\n", 'settings.google_play.package_name' => 'se.computercat.glosis'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $game->refresh()->settings['google_play']['service_account_json'];
        $this->assertStringNotContainsString($keyLine, $stored);
        $this->assertSame($json, Crypt::decryptString($stored));
        $this->assertSame(\Tests\Support\FakeGooglePlay::EMAIL, GlosisSettings::for($game)->googlePlayServiceAccount()['client_email']);

        // Laddas om: fältet är tomt, nyckeln syns inte, och sparas tomt behålls den.
        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.google_play.service_account_json' => null])
            ->assertDontSee($keyLine)
            ->assertDontSee($stored)
            ->assertSee(\Tests\Support\FakeGooglePlay::EMAIL)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($json, Crypt::decryptString($game->refresh()->settings['google_play']['service_account_json']));
        $this->assertSame('https://glosis.se', $game->settings['site_url']);
    }

    public function test_filament_stores_ai_limits_and_elevenlabs_settings(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => ['site_url' => 'https://glosis.se']]);

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.elevenlabs.api_key' => null])
            ->fillForm([
                'settings.scan.weekly_limit' => 20,
                'settings.scan.sandbox_weekly_limit' => 3,
                'settings.ai.monthly_budget_usd' => 75.5,
                'settings.tts.daily_new_limit' => 500,
                'settings.elevenlabs.api_key' => ' xi-hemlig ',
                'settings.elevenlabs.voice_id' => 'JBFqnCBsd6RMkjVDRZzb',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $game->refresh();
        $settings = \App\Services\Glosis\GlosisSettings::for($game);
        $this->assertSame(20, $settings->scanWeeklyLimit('Production'));
        $this->assertSame(3, $settings->scanWeeklyLimit('Sandbox'));
        $this->assertSame(75_500_000, $settings->monthlyBudgetMicroUsd());
        $this->assertSame(500, $settings->ttsDailyNewLimit());
        $this->assertSame('xi-hemlig', $settings->elevenLabsKey());
        $this->assertNotSame('xi-hemlig', $game->settings['elevenlabs']['api_key']);
        $this->assertSame('JBFqnCBsd6RMkjVDRZzb', $settings->elevenLabsVoiceId());
        $this->assertSame('https://glosis.se', $game->settings['site_url']);

        // Laddas om: nyckeln visas aldrig, och sparas tomt behålls den.
        $stored = $game->settings['elevenlabs']['api_key'];
        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.elevenlabs.api_key' => null, 'settings.elevenlabs.voice_id' => 'JBFqnCBsd6RMkjVDRZzb'])
            ->assertDontSee('xi-hemlig')
            ->assertDontSee($stored)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('xi-hemlig', \App\Services\Glosis\GlosisSettings::for($game->refresh())->elevenLabsKey());
    }

    public function test_filament_rejects_bad_voice_id_and_negative_limits(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $game = Game::where('slug', 'glosis')->firstOrFail();

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['settings.elevenlabs.voice_id' => '../v1/voices', 'settings.scan.weekly_limit' => -1])
            ->call('save')
            ->assertHasFormErrors(['settings.elevenlabs.voice_id', 'settings.scan.weekly_limit']);
    }

    public function test_glosis_ai_section_is_hidden_for_other_games(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => ['scan' => ['weekly_limit' => 7]]]);

        Livewire::test(EditGame::class, ['record' => $tocco->getRouteKey()])
            ->assertDontSee('Glosis: AI-kostnader')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(7, $tocco->refresh()->settings['scan']['weekly_limit']);
    }

    public function test_edit_form_loads_stored_settings_so_saving_does_not_wipe_them(): void
    {
        // Game::$hidden = ['settings'] gjorde att formuläret laddades tomt och
        // en sparning skrev null över sparade värden.
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $secret = Crypt::encryptString('whsec-hemlig');
        $game->update(['settings' => [
            'site_url' => 'https://glosis.se',
            'revenuecat' => ['ios_public_key' => 'appl_abc', 'webhook_secret' => $secret],
            'anti_cheat' => ['min_score' => 5],
            'scan' => ['weekly_limit' => 9],
            'elevenlabs' => ['voice_id' => 'Voice123'],
        ]]);

        $page = Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet([
                'settings.revenuecat.ios_public_key' => 'appl_abc',
                'settings.revenuecat.webhook_secret' => null,
                'settings.scan.weekly_limit' => 9,
                'settings.elevenlabs.voice_id' => 'Voice123',
            ])
            ->assertDontSee($secret)
            ->assertDontSee('whsec-hemlig');
        $this->assertStringNotContainsString($secret, json_encode($page->get('data')));
        $page->call('save')->assertHasNoFormErrors();

        $settings = $game->refresh()->settings;
        $this->assertSame('appl_abc', $settings['revenuecat']['ios_public_key']);
        $this->assertSame('whsec-hemlig', Crypt::decryptString($settings['revenuecat']['webhook_secret']));
        $this->assertEquals(5, $settings['anti_cheat']['min_score']);
        $this->assertEquals(9, $settings['scan']['weekly_limit']);
        $this->assertSame('Voice123', $settings['elevenlabs']['voice_id']);
        $this->assertSame('https://glosis.se', $settings['site_url']);
    }
}
