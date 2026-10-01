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
