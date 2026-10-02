<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('voices')
                ->label('Röster')
                ->icon('heroicon-o-speaker-wave')
                ->color('gray')
                ->visible(fn () => $this->getRecord()->slug === 'glosis')
                ->url(fn () => GameResource::getUrl('voices', ['record' => $this->getRecord()])),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Game::$hidden döljer settings (hemligheter) när modellen serialiseras, och
     * Filament fyller formuläret från attributesToArray(). Utan detta laddas
     * formuläret med tomma settings och en sparning skriver över sparade värden
     * (t.ex. RevenueCat-nycklar och Glosis gränser) med null. Hemligheterna
     * nollas innan de når formulärets state så att de aldrig skickas till webbläsaren.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $settings = $this->getRecord()->settings ?? [];
        foreach (GameResource::SECRET_SETTINGS as $path) {
            if (data_get($settings, $path) !== null) {
                data_set($settings, $path, null);
            }
        }
        $data['settings'] = $settings;

        return $data;
    }

    /**
     * Formuläret känner bara till en del av settings. Utan detta försvinner
     * nycklar som inte har ett fält (t.ex. glosis site_url) vid varje sparning.
     * Toppnivånycklar som formuläret äger ersätts helt, så borttag i dem
     * (t.ex. en rad i settings.extra) fungerar som förut. Undantag:
     * settings.elevenlabs.voices behålls alltid (sidan Röster).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Läses färskt ur databasen: sidan Röster kan ha ändrats sedan formuläret öppnades.
        $stored = $this->getRecord()->newQuery()->whereKey($this->getRecord()->getKey())->value('settings');
        $stored = is_string($stored) ? (json_decode($stored, true) ?: []) : ($stored ?? []);
        $data['settings'] = ($data['settings'] ?? []) + $stored;

        // settings.elevenlabs ägs av formuläret (nyckel och reservröst), men
        // rösterna per språk har inget fält här; de väljs på sidan Röster.
        if (array_key_exists('voices', $stored['elevenlabs'] ?? [])) {
            $data['settings']['elevenlabs']['voices'] = $stored['elevenlabs']['voices'];
        }

        return $data;
    }
}
