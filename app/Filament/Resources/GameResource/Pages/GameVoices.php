<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use App\Models\Game;
use App\Services\Glosis\ElevenLabsVoiceLibrary;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\StudioVoice;
use App\Services\Glosis\VoiceLibraryException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Röster: en aktiv ElevenLabs-röst per språk för Glosis studioröst. Rösten
 * sparas i settings.elevenlabs.voices.{språk} = {voice_id, name, category} och
 * används av StudioVoice direkt, utan ny appversion. Byts rösten byts
 * filnamnet (röst-id:t ingår i nyckeln), så gammalt ljud serveras inte.
 *
 * Sökningen läser ElevenLabs röstbibliotek eller kontots egna röster (se
 * ElevenLabsVoiceLibrary). Provlyssning på bibliotekets preview_url är gratis;
 * "Provlyssna i Glosis" genererar riktiga ord via StudioVoice och räknas mot
 * månadsbudgeten.
 */
class GameVoices extends Page
{
    use InteractsWithRecord;

    protected static string $resource = GameResource::class;

    protected static string $view = 'filament.resources.game-resource.pages.game-voices';

    protected static ?string $title = 'Röster';

    /** Orden som provlyssningen genererar, i båda hastigheterna. */
    public const PREVIEW_WORDS = ['grandmother', 'parrot'];

    /** Språket vars röst väljs just nu, eller null när ingen sökning är öppen. */
    public ?string $activeLanguage = null;

    /** 'library' (röstbiblioteket) eller 'mine' (kontots röster). */
    public string $source = 'library';

    public string $search = '';

    public string $filterLanguage = '';

    public string $accent = '';

    public string $gender = '';

    public string $age = '';

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public bool $searched = false;

    /** @var array<string, list<array{word: string, speed: string, url: string}>> */
    public array $previews = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless($this->record->slug === 'glosis', 404);
    }

    /** @return array<string, array<string, mixed>|null> */
    public function getVoices(): array
    {
        return GlosisSettings::for($this->freshGame())->elevenLabsVoices();
    }

    public function startChoosing(string $language): void
    {
        $this->assertLanguage($language);
        $this->activeLanguage = $language;
        $this->filterLanguage = $language;
        $this->results = [];
        $this->searched = false;
    }

    public function cancelChoosing(): void
    {
        $this->activeLanguage = null;
        $this->results = [];
        $this->searched = false;
    }

    public function searchVoices(): void
    {
        $library = app(ElevenLabsVoiceLibrary::class);
        if ($this->activeLanguage === null) {
            return;
        }
        $this->validate([
            'source' => 'required|in:library,mine',
            'search' => 'nullable|string|max:100',
            'filterLanguage' => 'nullable|string|max:10',
            'accent' => 'nullable|string|max:50',
            'gender' => 'nullable|string|max:20',
            'age' => 'nullable|string|max:20',
        ]);
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return;
        }

        try {
            $this->results = $this->source === 'mine'
                ? $library->myVoices($apiKey, $this->search)
                : $library->searchShared($apiKey, [
                    'search' => $this->search,
                    'language' => $this->filterLanguage,
                    'accent' => $this->accent,
                    'gender' => $this->gender,
                    'age' => $this->age,
                ]);
            $this->searched = true;
        } catch (VoiceLibraryException $e) {
            $this->results = [];
            $this->fail('Sökningen misslyckades', $e);
        }
    }

    public function selectVoice(int $index): void
    {
        $library = app(ElevenLabsVoiceLibrary::class);
        $language = $this->activeLanguage;
        $voice = $this->results[$index] ?? null;
        if ($language === null || ! is_array($voice) || ! GlosisSettings::validVoiceId($voice['voice_id'] ?? null)) {
            return;
        }
        if (($voice['expiring'] ?? false) === true) {
            Notification::make()->danger()->persistent()
                ->title('Rösten kan inte väljas')
                ->body('Det är en ElevenLabs Default-röst som upphör 31 dec 2026. Välj en röst ur röstbiblioteket i stället.')
                ->send();

            return;
        }
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return;
        }

        $voiceId = $voice['voice_id'];
        try {
            if ($voice['source'] === 'library') {
                if ($voice['is_added_by_user'] === true) {
                    // Redan i kontot: använd den om den finns där under samma id.
                    $own = $library->findOwnVoice($apiKey, $voiceId);
                    if ($own === null) {
                        Notification::make()->danger()->persistent()
                            ->title('Rösten hittades inte i kontot')
                            ->body('ElevenLabs säger att rösten redan är tillagd, men den finns inte under samma id. Sök under Mina röster och välj den där.')
                            ->send();

                        return;
                    }
                } else {
                    $voiceId = $library->addShared($apiKey, (string) $voice['public_owner_id'], $voiceId, (string) $voice['name']);
                }
            }
        } catch (VoiceLibraryException $e) {
            $this->fail('Rösten gick inte att lägga till', $e);

            return;
        }

        $this->storeVoice($language, $voiceId, (string) $voice['name'], $voice['category'] ?? null);
        unset($this->previews[$language]);
        $this->cancelChoosing();

        Notification::make()->success()
            ->title('Röst vald för '.mb_strtolower(GlosisSettings::LANGUAGES[$language]))
            ->body("{$voice['name']} ({$voiceId}). Nya ord genereras med den här rösten; tidigare ljud används inte längre.")
            ->send();
    }

    /** Genererar provorden med språkets röst via StudioVoice (cache, ai_usage och budget som i appen). */
    public function previewVoice(string $language): void
    {
        $studioVoice = app(StudioVoice::class);
        $this->assertLanguage($language);
        $game = $this->freshGame();
        if (GlosisSettings::for($game)->elevenLabsVoiceId($language) === null) {
            Notification::make()->danger()->persistent()->title('Ingen röst vald för '.mb_strtolower(GlosisSettings::LANGUAGES[$language]))->send();

            return;
        }

        $clips = [];
        $problems = [];
        foreach (array_keys(StudioVoice::SPEEDS) as $speed) {
            $result = $studioVoice->prepare($game, StudioVoice::adminTransaction(), $speed, self::PREVIEW_WORDS, $language);
            foreach (self::PREVIEW_WORDS as $word) {
                if (isset($result['urls'][$word])) {
                    $clips[] = ['word' => $word, 'speed' => $speed, 'url' => $result['urls'][$word]];
                }
            }
            if ($result['skipped'] !== []) {
                $problems[] = $this->previewProblem($result['stopped'], $result['skipped'], $studioVoice);
            }
        }

        $this->previews[$language] = $clips;
        if ($problems !== []) {
            Notification::make()->danger()->persistent()
                ->title('Provlyssningen blev inte komplett')
                ->body(implode(' ', array_unique($problems)))
                ->send();
        }
    }

    /** @param list<array{word: string, reason: string}> $skipped */
    private function previewProblem(?string $stopped, array $skipped, StudioVoice $studioVoice): string
    {
        if ($stopped === 'budget') {
            return 'Månadens AI-budget är slut (ändras under Games → Glosis).';
        }
        if ($studioVoice->lastFailure !== null) {
            $context = $studioVoice->lastFailure->context;
            if (isset($context['status'])) {
                return ElevenLabsVoiceLibrary::describeError((int) $context['status'], (string) ($context['response'] ?? ''))['message'];
            }

            return 'ElevenLabs gick inte att nå.';
        }
        if (in_array('limit', array_column($skipped, 'reason'), true)) {
            return 'Dygnsgränsen för nya studioröst-ord är nådd.';
        }
        if ($stopped === 'unavailable' && GlosisSettings::for($this->freshGame())->elevenLabsKey() === null) {
            return 'Ingen ElevenLabs-nyckel är sparad.';
        }

        return 'Studiorösten svarade inte (se loggen).';
    }

    private function storeVoice(string $language, string $voiceId, string $name, ?string $category): void
    {
        DB::transaction(function () use ($language, $voiceId, $name, $category) {
            /** @var Game $game */
            $game = Game::whereKey($this->record->getKey())->lockForUpdate()->firstOrFail();
            $settings = $game->settings ?? [];
            data_set($settings, "elevenlabs.voices.{$language}", [
                'voice_id' => $voiceId,
                'name' => mb_substr($name, 0, 100),
                'category' => $category,
            ]);
            $game->settings = $settings;
            $game->save();
        });

        Log::info('Glosis röster: röst vald', ['language' => $language, 'voice_id' => $voiceId]);
    }

    private function apiKey(): ?string
    {
        $key = GlosisSettings::for($this->freshGame())->elevenLabsKey();
        if ($key === null) {
            Notification::make()->danger()->persistent()
                ->title('Ingen ElevenLabs-nyckel')
                ->body('Spara en nyckel under Games → Glosis → Glosis: AI-kostnader. Nyckeln behöver behörigheterna voices_read och voices_write för att söka och lägga till röster.')
                ->send();
        }

        return $key;
    }

    private function fail(string $title, VoiceLibraryException $e): void
    {
        Notification::make()->danger()->persistent()->title($title)->body($e->getMessage())->send();
    }

    private function assertLanguage(string $language): void
    {
        abort_unless(array_key_exists($language, GlosisSettings::LANGUAGES), 422);
    }

    private function freshGame(): Game
    {
        return Game::whereKey($this->record->getKey())->firstOrFail();
    }
}
