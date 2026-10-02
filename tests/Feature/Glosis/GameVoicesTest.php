<?php

namespace Tests\Feature\Glosis;

use App\Filament\Resources\GameResource;
use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\Pages\GameVoices;
use App\Models\AiUsage;
use App\Models\Game;
use App\Models\User;
use App\Services\Glosis\ElevenLabsVoiceLibrary;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\StudioVoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GameVoicesTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'xi-test-not-a-real-key';

    private const MP3 = "\xFF\xFB\x90\x64".'ljud';

    private Game $game;

    /** @var list<HttpRequest> */
    private array $requests = [];

    /** @var array<string, \Closure> url-mönster => svar */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => [
            'site_url' => 'https://glosis.se',
            'elevenlabs' => ['api_key' => Crypt::encryptString(self::API_KEY), 'voice_id' => 'LegacyVoice1'],
        ]]);

        config(['app.url' => 'https://api.computercat.co']);
        Storage::fake(StudioVoice::DISK);
        Http::preventStrayRequests();
        Http::fake(['api.elevenlabs.io/*' => function (HttpRequest $request) {
            $this->requests[] = $request;
            foreach ($this->answers as $pattern => $answer) {
                if (str_contains($request->url(), $pattern)) {
                    return $answer($request);
                }
            }

            return Http::response(['detail' => 'ingen fejk för '.$request->url()], 599);
        }]);
    }

    private function answer(string $pattern, \Closure $answer): void
    {
        $this->answers[$pattern] = $answer;
    }

    private function page()
    {
        return Livewire::test(GameVoices::class, ['record' => $this->game->getRouteKey()]);
    }

    /** Ett LibraryVoiceResponseModel enligt dokumentationens exempel. */
    private static function sharedVoice(array $overrides = []): array
    {
        return array_merge([
            'public_owner_id' => 'abc123owner',
            'voice_id' => 'SharedVoice1',
            'date_unix' => 1714423232,
            'name' => 'Eldrin - Crisp British Baritone',
            'accent' => 'british',
            'gender' => 'male',
            'age' => 'middle_aged',
            'descriptive' => 'crisp',
            'use_case' => 'narrative_story',
            'category' => 'professional',
            'usage_character_count_1y' => 1,
            'usage_character_count_7d' => 1,
            'play_api_usage_character_count_1y' => 1,
            'cloned_by_count' => 1,
            'free_users_allowed' => true,
            'live_moderation_enabled' => false,
            'featured' => false,
            'language' => 'en',
            'description' => 'Crisp British baritone for clear narration.',
            'preview_url' => 'https://storage.googleapis.com/eleven-public-prod/voices/SharedVoice1/preview.mp3',
            'is_added_by_user' => false,
        ], $overrides);
    }

    private function sharedVoicesAnswer(array $voices): void
    {
        $this->answer('/v1/shared-voices', fn () => Http::response(['voices' => $voices, 'has_more' => false, 'total_count' => count($voices)]));
    }

    private function missingPermission(string $permission): \Closure
    {
        // Svaret ElevenLabs gav 2026-10-02 för en nyckel utan voices_read.
        return fn () => Http::response(['detail' => [
            'type' => 'authentication_error',
            'code' => 'unauthorized',
            'message' => "The API key you used is missing the permission {$permission} to execute this operation.",
            'status' => 'missing_permissions',
            'request_id' => 'req123',
        ]], 401);
    }

    /** @return list<array{title: string|null, body: string|null, status: string|null}> */
    private function notifications(): array
    {
        return collect(session('filament.notifications', []))
            ->map(fn ($n) => ['title' => $n['title'] ?? null, 'body' => $n['body'] ?? null, 'status' => $n['status'] ?? null])
            ->all();
    }

    // ---- Sidan ---------------------------------------------------------

    public function test_page_shows_each_language_and_the_legacy_english_voice(): void
    {
        $this->get(GameResource::getUrl('voices', ['record' => $this->game]))->assertOk();

        $this->page()
            ->assertSee(['Engelska', 'Tyska', 'Spanska', 'Franska'])
            ->assertSee(['Kvinnlig röst', 'Manlig röst'])
            ->assertSeeHtml(['data-test="voice-row-en-female"', 'data-test="voice-row-en-male"', 'data-test="voice-row-fr-male"'])
            ->assertSee('LegacyVoice1')
            ->assertSee('Från det äldre fältet')
            ->assertSee('Appen använder telefonens röst för tyska.')
            ->assertDontSee('Appen använder telefonens röst för engelska.')
            ->assertDontSee(self::API_KEY);
        $this->assertSame([], $this->requests, 'Sidan anropar inte ElevenLabs förrän något söks');
    }

    public function test_page_is_only_for_glosis(): void
    {
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);

        $this->get(GameResource::getUrl('voices', ['record' => $tocco]))->assertNotFound();
    }

    public function test_edit_page_and_table_link_to_the_voices_page(): void
    {
        Livewire::test(EditGame::class, ['record' => $this->game->getRouteKey()])->assertActionVisible('voices');
        $this->get(GameResource::getUrl('index'))->assertOk()->assertSee(GameResource::getUrl('voices', ['record' => $this->game]), false);
    }

    // ---- Sökning -------------------------------------------------------

    public function test_search_calls_the_shared_voice_library_with_filters_and_hides_default_voices(): void
    {
        $this->sharedVoicesAnswer([
            self::sharedVoice(),
            self::sharedVoice(['voice_id' => 'PremadeVoice1', 'name' => 'George', 'category' => 'premade']),
            self::sharedVoice(['voice_id' => 'BadUrlVoice1', 'name' => 'Utan prov', 'preview_url' => 'javascript:alert(1)']),
            self::sharedVoice(['voice_id' => '../v1/models', 'name' => 'Fel id']),
        ]);

        $page = $this->page()
            ->call('startChoosing', 'en', 'female')
            ->set('search', 'baritone')
            ->set('accent', 'british')
            ->set('gender', 'male')
            ->set('age', 'middle_aged')
            ->call('searchVoices')
            ->assertSee('Eldrin - Crisp British Baritone')
            ->assertSee('Crisp British baritone for clear narration.')
            ->assertSee('<audio controls preload="none" src="https://storage.googleapis.com/eleven-public-prod/voices/SharedVoice1/preview.mp3"', false)
            ->assertDontSee('George')
            ->assertDontSee('Fel id')
            ->assertDontSee('javascript:');

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(self::API_KEY, $request->header('xi-api-key')[0]);
        $this->assertStringStartsWith('https://api.elevenlabs.io/v1/shared-voices?', $request->url());
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame([
            'page_size' => '30',
            'search' => 'baritone',
            'language' => 'en',
            'accent' => 'british',
            'gender' => 'male',
            'age' => 'middle_aged',
        ], $query);
        $this->assertSame(['SharedVoice1', 'BadUrlVoice1'], array_column($page->get('results'), 'voice_id'));
        $this->assertNull($page->get('results')[1]['preview_url']);
    }

    public function test_my_voices_lists_account_voices_and_marks_default_voices_as_expiring(): void
    {
        $this->answer('/v2/voices', fn () => Http::response(['voices' => [
            ['voice_id' => 'MyClone1', 'name' => 'Min klon', 'category' => 'cloned', 'labels' => ['accent' => 'british', 'gender' => 'female'], 'preview_url' => 'https://example.com/p.mp3'],
            ['voice_id' => 'JBFqnCBsd6RMkjVDRZzb', 'name' => 'George', 'category' => 'premade', 'labels' => ['accent' => 'british']],
        ], 'has_more' => false, 'total_count' => 2]));

        $page = $this->page()
            ->call('startChoosing', 'en', 'female')
            ->set('source', 'mine')
            ->set('search', 'george')
            ->call('searchVoices')
            ->assertSee('Min klon')
            ->assertSee('George')
            ->assertSee('Default-röst: upphör 31 dec 2026')
            ->assertSee('Kan inte väljas');

        $this->assertStringStartsWith('https://api.elevenlabs.io/v2/voices?', $this->requests[0]->url());
        parse_str(parse_url($this->requests[0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['page_size' => '100', 'include_total_count' => 'false', 'search' => 'george'], $query);

        // En Default-röst går inte att välja ens via ett direkt anrop.
        $page->call('selectVoice', 1);
        $this->assertNull(data_get($this->game->refresh()->settings, 'elevenlabs.voices.en'));
        $this->assertSame('Rösten kan inte väljas', $this->notifications()[0]['title']);

        // En egen röst väljs direkt, utan att läggas till.
        $page->call('selectVoice', 0);
        $this->assertSame(['female' => ['voice_id' => 'MyClone1', 'name' => 'Min klon', 'category' => 'cloned']], $this->game->refresh()->settings['elevenlabs']['voices']['en']);
        $this->assertCount(1, $this->requests);
    }

    public function test_missing_permission_is_shown_by_name(): void
    {
        $this->answer('/v1/shared-voices', $this->missingPermission('voices_read'));

        $page = $this->page()->call('startChoosing', 'en', 'female')->call('searchVoices');

        $this->assertSame([], $page->get('results'));
        $notification = $this->notifications()[0];
        $this->assertSame('danger', $notification['status']);
        $this->assertSame('Sökningen misslyckades', $notification['title']);
        $this->assertSame('ElevenLabs-nyckeln saknar behörigheten voices_read. Lägg till den på nyckeln under elevenlabs.io → API Keys och försök igen.', $notification['body']);
    }

    public function test_missing_key_is_shown_without_calling_elevenlabs(): void
    {
        $this->game->update(['settings' => ['elevenlabs' => ['voice_id' => 'LegacyVoice1']]]);

        $this->page()->call('startChoosing', 'en', 'female')->call('searchVoices');

        $this->assertSame('Ingen ElevenLabs-nyckel', $this->notifications()[0]['title']);
        $this->assertSame([], $this->requests);
    }

    // ---- Val -----------------------------------------------------------

    public function test_selecting_a_library_voice_adds_it_to_the_account_and_stores_it_for_the_language(): void
    {
        $this->sharedVoicesAnswer([self::sharedVoice(['language' => 'de', 'name' => 'Greta'])]);
        $this->answer('/v1/voices/add/', fn () => Http::response(['voice_id' => 'AddedVoice9']));

        $this->page()->call('startChoosing', 'de', 'female')->call('searchVoices')->call('selectVoice', 0)
            ->assertSet('activeLanguage', null);

        $add = $this->requests[1];
        $this->assertSame('POST', $add->method());
        $this->assertSame('https://api.elevenlabs.io/v1/voices/add/abc123owner/SharedVoice1', $add->url());
        $this->assertSame(['new_name' => 'Greta'], $add->data());
        $this->assertSame(self::API_KEY, $add->header('xi-api-key')[0]);

        $settings = $this->game->refresh()->settings;
        $this->assertSame(['female' => ['voice_id' => 'AddedVoice9', 'name' => 'Greta', 'category' => 'professional']], $settings['elevenlabs']['voices']['de']);
        $this->assertSame('LegacyVoice1', $settings['elevenlabs']['voice_id'], 'Det äldre fältet rörs inte');
        $this->assertSame(self::API_KEY, Crypt::decryptString($settings['elevenlabs']['api_key']));
        $this->assertSame('https://glosis.se', $settings['site_url']);
        $this->assertSame('AddedVoice9', GlosisSettings::for($this->game)->elevenLabsVoiceId('de'));
        $this->assertSame('LegacyVoice1', GlosisSettings::for($this->game)->elevenLabsVoiceId('en'));
        $this->assertSame('success', $this->notifications()[0]['status']);
    }

    public function test_voice_already_in_the_account_is_not_added_again(): void
    {
        $this->sharedVoicesAnswer([self::sharedVoice(['is_added_by_user' => true])]);
        $this->answer('/v2/voices', fn () => Http::response(['voices' => [['voice_id' => 'SharedVoice1', 'name' => 'Eldrin', 'category' => 'professional']], 'has_more' => false, 'total_count' => 1]));

        $this->page()->call('startChoosing', 'en', 'female')->call('searchVoices')->call('selectVoice', 0);

        $this->assertCount(2, $this->requests);
        parse_str(parse_url($this->requests[1]->url(), PHP_URL_QUERY), $query);
        $this->assertSame('SharedVoice1', $query['voice_ids']);
        $this->assertSame('SharedVoice1', $this->game->refresh()->settings['elevenlabs']['voices']['en']['female']['voice_id']);
    }

    public function test_add_without_voices_write_permission_stores_nothing(): void
    {
        $this->sharedVoicesAnswer([self::sharedVoice()]);
        $this->answer('/v1/voices/add/', $this->missingPermission('voices_write'));

        $this->page()->call('startChoosing', 'en', 'female')->call('searchVoices')->call('selectVoice', 0);

        $this->assertNull(data_get($this->game->refresh()->settings, 'elevenlabs.voices'));
        $notification = $this->notifications()[0];
        $this->assertSame('Rösten gick inte att lägga till', $notification['title']);
        $this->assertStringContainsString('saknar behörigheten voices_write', $notification['body']);
    }

    public function test_saving_the_edit_form_keeps_the_voices_chosen_on_the_voices_page(): void
    {
        // Formuläret öppnas först, sedan väljs en röst i en annan flik.
        $form = Livewire::test(EditGame::class, ['record' => $this->game->getRouteKey()]);
        $settings = $this->game->refresh()->settings;
        $settings['elevenlabs']['voices'] = ['en' => ['voice_id' => 'ChosenVoice1', 'name' => 'Vald', 'category' => 'professional']];
        $this->game->update(['settings' => $settings]);

        $form->fillForm(['settings.scan.weekly_limit' => 9])->call('save')->assertHasNoFormErrors();

        $settings = $this->game->refresh()->settings;
        $this->assertSame('ChosenVoice1', $settings['elevenlabs']['voices']['en']['voice_id']);
        $this->assertEquals(9, $settings['scan']['weekly_limit']);
        $this->assertSame(self::API_KEY, Crypt::decryptString($settings['elevenlabs']['api_key']));
    }

    public function test_unknown_language_is_rejected(): void
    {
        $this->page()->call('startChoosing', 'sv', 'female')->assertStatus(422);
        $this->page()->call('startChoosing', 'en', 'child')->assertStatus(422);
        $this->page()->call('previewVoice', 'en', 'child')->assertStatus(422);
        $this->assertSame([], $this->requests);
    }

    // ---- Kvinnlig och manlig röst --------------------------------------

    public function test_choosing_prefills_the_gender_filter_and_it_stays_editable(): void
    {
        $this->sharedVoicesAnswer([self::sharedVoice()]);

        $page = $this->page()->call('startChoosing', 'de', 'male')
            ->assertSet('activeLanguage', 'de')->assertSet('activeGender', 'male')->assertSet('gender', 'male')
            ->assertSee('Välj manlig röst för tyska')
            ->call('searchVoices');
        parse_str(parse_url($this->requests[0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame('male', $query['gender']);
        $this->assertSame('de', $query['language']);

        $page->set('gender', '')->call('searchVoices');
        parse_str(parse_url($this->requests[1]->url(), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('gender', $query);

        $this->page()->call('startChoosing', 'de', 'female')->assertSet('gender', 'female');
    }

    public function test_choosing_male_keeps_the_female_voice_and_moves_the_old_single_voice_to_female(): void
    {
        $settings = $this->game->settings;
        $settings['elevenlabs']['voices'] = ['de' => ['voice_id' => 'OldGerman1', 'name' => 'Greta', 'category' => 'professional']];
        $this->game->update(['settings' => $settings]);
        $this->sharedVoicesAnswer([self::sharedVoice(['name' => 'Hans'])]);
        $this->answer('/v1/voices/add/', fn () => Http::response(['voice_id' => 'GermanMale1']));

        $this->page()->call('startChoosing', 'de', 'male')->call('searchVoices')->call('selectVoice', 0)
            ->assertSet('activeLanguage', null)->assertSet('activeGender', null);

        $settings = $this->game->refresh()->settings;
        $this->assertSame([
            'female' => ['voice_id' => 'OldGerman1', 'name' => 'Greta', 'category' => 'professional'],
            'male' => ['voice_id' => 'GermanMale1', 'name' => 'Hans', 'category' => 'professional'],
        ], $settings['elevenlabs']['voices']['de']);
        $this->assertSame('LegacyVoice1', $settings['elevenlabs']['voice_id']);
        $this->assertSame('Manlig röst för tyska vald', $this->notifications()[0]['title']);

        $glosis = GlosisSettings::for($this->game);
        $this->assertSame('OldGerman1', $glosis->elevenLabsVoiceId('de', 'female'));
        $this->assertSame('GermanMale1', $glosis->elevenLabsVoiceId('de', 'male'));

        // Kvinnlig väljs sedan: den manliga finns kvar.
        $this->sharedVoicesAnswer([self::sharedVoice(['voice_id' => 'SharedVoice2', 'name' => 'Lena'])]);
        $this->answer('/v1/voices/add/', fn () => Http::response(['voice_id' => 'GermanFemale2']));
        $this->page()->call('startChoosing', 'de', 'female')->call('searchVoices')->call('selectVoice', 0);
        $voices = $this->game->refresh()->settings['elevenlabs']['voices']['de'];
        $this->assertSame(['female', 'male'], array_keys($voices));
        $this->assertSame('GermanFemale2', $voices['female']['voice_id']);
        $this->assertSame('GermanMale1', $voices['male']['voice_id']);
    }

    public function test_choosing_english_male_leaves_the_legacy_female_voice(): void
    {
        $this->sharedVoicesAnswer([self::sharedVoice()]);
        $this->answer('/v1/voices/add/', fn () => Http::response(['voice_id' => 'EnglishMale1']));

        $this->page()->call('startChoosing', 'en', 'male')->call('searchVoices')->call('selectVoice', 0);

        $settings = $this->game->refresh()->settings;
        $this->assertSame(['male'], array_keys($settings['elevenlabs']['voices']['en']));
        $voices = GlosisSettings::for($this->game)->elevenLabsVoices()['en'];
        $this->assertSame(['voice_id' => 'LegacyVoice1', 'name' => null, 'category' => null, 'source' => 'legacy'], $voices['female']);
        $this->assertSame('EnglishMale1', $voices['male']['voice_id']);
        $this->assertSame('voices', $voices['male']['source']);
    }

    public function test_preview_uses_only_that_slots_voice(): void
    {
        $settings = $this->game->settings;
        $settings['elevenlabs']['voices'] = ['es' => [
            'female' => ['voice_id' => 'SpanishFemale1', 'name' => 'Lucía'],
            'male' => ['voice_id' => 'SpanishMale1', 'name' => 'Pablo'],
        ], 'fr' => ['female' => ['voice_id' => 'FrenchFemale1', 'name' => 'Amélie']]];
        $this->game->update(['settings' => $settings]);
        $this->answer('/v1/text-to-speech/', fn () => Http::response(self::MP3, 200, ['Content-Type' => 'audio/mpeg']));

        $page = $this->page()->call('previewVoice', 'es', 'male');
        $this->assertCount(4, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertStringStartsWith('https://api.elevenlabs.io/v1/text-to-speech/SpanishMale1?', $request->url());
        }
        $page->call('previewVoice', 'es', 'female');
        $this->assertCount(8, $this->requests);
        foreach (array_slice($this->requests, 4) as $request) {
            $this->assertStringStartsWith('https://api.elevenlabs.io/v1/text-to-speech/SpanishFemale1?', $request->url());
        }

        $previews = $page->get('previews');
        $this->assertSame(['es-male', 'es-female'], array_keys($previews));
        $this->assertStringContainsString(StudioVoice::hash('grandmother', 'normal', 'SpanishMale1', 'es'), $previews['es-male'][0]['url']);
        $this->assertStringContainsString(StudioVoice::hash('grandmother', 'normal', 'SpanishFemale1', 'es'), $previews['es-female'][0]['url']);
        $page->assertSeeHtml(['data-test="previews-es-male"', 'data-test="previews-es-female"']);

        // En plats utan röst provlyssnas inte med språkets andra röst.
        $this->page()->call('previewVoice', 'fr', 'male');
        $this->assertCount(8, $this->requests);
        $this->assertSame('Ingen manlig röst för franska vald', collect($this->notifications())->last()['title']);
    }

    public function test_settings_read_the_new_shape_and_both_older_shapes(): void
    {
        $settings = new GlosisSettings(['elevenlabs' => [
            'voice_id' => 'LegacyVoice1',
            'voices' => [
                'de' => ['female' => ['voice_id' => 'GermanFemale1', 'name' => 'Greta'], 'male' => ['voice_id' => 'GermanMale1', 'name' => 'Hans', 'category' => 'professional']],
                'es' => ['voice_id' => 'OldSpanish1', 'name' => 'Lucía'],
                'fr' => ['male' => ['voice_id' => 'FrenchMale1']],
            ],
        ]]);

        $this->assertSame('GermanFemale1', $settings->elevenLabsVoiceId('de', 'female'));
        $this->assertSame('GermanMale1', $settings->elevenLabsVoiceId('de', 'male'));
        $this->assertSame('OldSpanish1', $settings->elevenLabsVoiceId('es', 'female'));
        $this->assertNull($settings->elevenLabsVoiceId('es', 'male'));
        $this->assertSame('LegacyVoice1', $settings->elevenLabsVoiceId('en', 'female'));
        $this->assertSame('LegacyVoice1', $settings->elevenLabsVoiceId('en'));
        $this->assertNull($settings->elevenLabsVoiceId('en', 'male'));
        $this->assertNull($settings->elevenLabsVoiceId('fr', 'female'));
        $this->assertNull($settings->elevenLabsVoiceId('de', 'child'));

        $this->assertSame(['voice_id' => 'OldSpanish1', 'gender' => 'female'], $settings->studioVoice('es', 'male'));
        $this->assertSame(['voice_id' => 'FrenchMale1', 'gender' => 'male'], $settings->studioVoice('fr', 'female'));
        $this->assertSame(['voice_id' => 'LegacyVoice1', 'gender' => 'female'], $settings->studioVoice('en', 'male'));

        $voices = $settings->elevenLabsVoices();
        $this->assertSame(['en', 'de', 'es', 'fr'], array_keys($voices));
        $this->assertSame(['voice_id' => 'GermanMale1', 'name' => 'Hans', 'category' => 'professional', 'source' => 'voices'], $voices['de']['male']);
        $this->assertSame(['voice_id' => 'OldSpanish1', 'name' => 'Lucía', 'category' => null, 'source' => 'single'], $voices['es']['female']);
        $this->assertNull($voices['es']['male']);
        $this->assertSame('legacy', $voices['en']['female']['source']);
        $this->assertNull($voices['fr']['female']);

        $this->assertSame(['female' => ['voice_id' => 'OldSpanish1', 'name' => 'Lucía']], $settings->storedVoicesFor('es'));
        $this->assertSame(['male' => ['voice_id' => 'FrenchMale1']], $settings->storedVoicesFor('fr'));
        $this->assertSame([], $settings->storedVoicesFor('en'));

        // Ingen röst alls sparad (som i drift nu).
        $empty = new GlosisSettings([]);
        $this->assertNull($empty->studioVoice('en', 'female'));
        $this->assertSame(['female' => null, 'male' => null], $empty->elevenLabsVoices()['en']);
    }

    // ---- Provlyssna i Glosis -------------------------------------------

    public function test_preview_generates_both_words_in_both_speeds_with_the_chosen_voice(): void
    {
        $settings = $this->game->settings;
        $settings['elevenlabs']['voices'] = ['fr' => ['voice_id' => 'FrenchVoice1', 'name' => 'Amélie']];
        $this->game->update(['settings' => $settings]);
        $this->answer('/v1/text-to-speech/', fn () => Http::response(self::MP3, 200, ['Content-Type' => 'audio/mpeg']));

        $page = $this->page()->call('previewVoice', 'fr', 'female');

        $this->assertCount(4, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertStringStartsWith('https://api.elevenlabs.io/v1/text-to-speech/FrenchVoice1?', $request->url());
            $this->assertSame('fr', $request->data()['language_code']);
        }
        $this->assertEqualsCanonicalizing(
            ['grandmother|1', 'parrot|1', 'grandmother|0.7', 'parrot|0.7'],
            array_map(fn ($r) => $r->data()['text'].'|'.$r->data()['voice_settings']['speed'], $this->requests),
        );

        // Samma cache som appen: filerna ligger under appens nycklar.
        foreach (['grandmother', 'parrot'] as $word) {
            foreach (['normal', 'slow'] as $speed) {
                Storage::disk(StudioVoice::DISK)->assertExists(StudioVoice::hash($word, $speed, 'FrenchVoice1', 'fr').'.mp3');
            }
        }
        $this->assertSame(4, AiUsage::where('feature', 'tts')->where('environment', 'Admin')->count());

        $clips = $page->get('previews')['fr-female'];
        $this->assertCount(4, $clips);
        $this->assertStringStartsWith('https://api.computercat.co/api/v1/games/glosis/tts/audio/', $clips[0]['url']);
        $page->assertSee('<audio controls preload="none" src="'.e($clips[0]['url']).'"', false);
        $this->get($clips[0]['url'])->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

        // En gång till: allt finns sparat, inga nya anrop eller kostnader.
        $this->page()->call('previewVoice', 'fr', 'female');
        $this->assertCount(4, $this->requests);
        $this->assertSame(4, AiUsage::count());
    }

    public function test_preview_respects_the_monthly_budget(): void
    {
        $settings = $this->game->settings;
        $settings['ai'] = ['monthly_budget_usd' => 0];
        $this->game->update(['settings' => $settings]);

        $this->page()->call('previewVoice', 'en', 'female');

        $this->assertSame([], $this->requests);
        $this->assertSame(0, AiUsage::count());
        $this->assertStringContainsString('Månadens AI-budget är slut', $this->notifications()[0]['body']);
    }

    public function test_preview_shows_a_text_to_speech_permission_error(): void
    {
        $this->answer('/v1/text-to-speech/', $this->missingPermission('text_to_speech'));

        $this->page()->call('previewVoice', 'en', 'female');

        $this->assertSame(0, AiUsage::count());
        $this->assertStringContainsString('saknar behörigheten text_to_speech', $this->notifications()[0]['body']);
    }

    public function test_describe_error_handles_other_shapes(): void
    {
        $this->assertSame(
            ['message' => 'ElevenLabs godtog inte nyckeln (401). Kontrollera ElevenLabs-nyckeln under Games → Glosis.', 'permission' => null],
            ElevenLabsVoiceLibrary::describeError(401, '{"detail":{"status":"invalid_api_key","message":"Invalid API key"}}'),
        );
        $this->assertSame('ElevenLabs svarade 422: Fel värde', ElevenLabsVoiceLibrary::describeError(422, '{"detail":"Fel värde"}')['message']);
        $this->assertSame('ElevenLabs svarade 500', ElevenLabsVoiceLibrary::describeError(500, 'inte json')['message']);
    }
}
