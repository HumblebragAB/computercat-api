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
        return [Actions\DeleteAction::make()];
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
     * (t.ex. en rad i settings.extra) fungerar som förut.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = ($data['settings'] ?? []) + ($this->getRecord()->settings ?? []);

        return $data;
    }
}
