<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use App\Services\IntegrationGuide;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

// Manualen byggs av IntegrationGuide (samma text som MCP-verktyget
// get_integration_guide). Sidan skapar inga tokens.
class GameDevKit extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = GameResource::class;

    protected static string $view = 'filament.resources.game-resource.pages.game-dev-kit';

    protected static ?string $title = 'Developer Kit';

    public array $features = ['auth', 'leaderboards', 'achievements', 'saves'];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->features = IntegrationGuide::defaultFeatures($this->record);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            CheckboxList::make('features')
                ->label('Features to include')
                ->options(IntegrationGuide::FEATURES)
                ->live(),
        ]);
    }

    public function getSetupGuide(): string
    {
        return IntegrationGuide::setupGuide($this->record);
    }

    private ?string $cachedManual = null;

    // Cachen lever bara under en rendering: privata egenskaper följer inte
    // med mellan Livewire-anrop, så ändrade features ger ny manual.
    public function getManual(): string
    {
        return $this->cachedManual ??= IntegrationGuide::manual($this->record, $this->features);
    }
}
