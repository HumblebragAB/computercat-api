<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Models\Product;
use App\Services\ProductSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Products (IAP)';

    private const PRODUCT_TYPES = [
        'non_consumable' => 'Non-Consumable (permanent)',
        'consumable' => 'Consumable (can be bought multiple times)',
        'subscription' => 'Subscription',
    ];

    private const GRANT_TYPES = [
        'pack' => 'Content pack',
        'theme_pack' => 'Theme pack',
        'supporter' => 'Supporter (unlocks everything)',
        'custom' => 'Custom',
    ];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('product_id')
                        ->required()
                        ->maxLength(100)
                        ->helperText('Must match App Store / Play Store product ID (e.g. "tocco_pack_6x6_medium")'),
                    Forms\Components\TextInput::make('reference_name')
                        ->required()
                        ->maxLength(100)
                        ->helperText('Internal name shown in App Store Connect admin'),
                    Forms\Components\Select::make('product_type')
                        ->options(self::PRODUCT_TYPES)
                        ->default('non_consumable')
                        ->required()
                        // Select validerar inte mot sina options.
                        ->rules([Rule::in(array_keys(self::PRODUCT_TYPES))]),
                    Forms\Components\Toggle::make('is_active')->default(true),
                ]),

            Forms\Components\Section::make('Grant')
                ->description('What buying this product unlocks in the game')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('grant_type')
                        ->options(self::GRANT_TYPES)
                        ->required()
                        ->rules([Rule::in(array_keys(self::GRANT_TYPES))])
                        ->live(),
                    Forms\Components\TextInput::make('grant_id')
                        ->label('Grant ID')
                        ->helperText('The pack or theme-pack identifier this product unlocks')
                        ->visible(fn (Get $get) => in_array($get('grant_type'), ['pack', 'theme_pack', 'custom'])),
                ]),

            Forms\Components\Section::make('Pricing')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('price')
                        ->required()
                        ->numeric()
                        ->step(0.01)
                        ->helperText('Price in base currency'),
                    Forms\Components\TextInput::make('currency')
                        ->default('SEK')
                        ->maxLength(3)
                        ->required(),
                ]),

            Forms\Components\Section::make('Store Listing')
                ->description('What users see in the App Store / Play Store purchase dialog')
                ->schema([
                    Forms\Components\TextInput::make('display_name')
                        ->required()
                        ->maxLength(100)
                        ->helperText('Shown in the purchase dialog (max 30 chars recommended)'),
                    Forms\Components\Textarea::make('description')
                        ->required()
                        ->rows(3)
                        ->helperText('1-2 sentences explaining what the user gets'),
                    Forms\Components\Textarea::make('review_notes')
                        ->rows(2)
                        ->helperText('Notes for Apple reviewers (e.g. how to trigger the purchase)'),
                    Forms\Components\FileUpload::make('review_screenshot_path')
                        ->label('Review screenshot')
                        ->image()
                        ->imageEditor()
                        ->disk('local')
                        ->directory('review-screenshots')
                        ->helperText('Apple requires 640×920 PNG. Same image can be reused across all products for a game. Resize/crop with the built-in editor.')
                        ->downloadable(),
                ]),

            Forms\Components\Section::make('Ordering')
                ->schema([
                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()
                        ->default(0),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->description('Products are the source of truth. After creating/editing, run `php artisan iap:sync '.$this->getOwnerRecord()->slug.'` to push to App Store Connect. Price and review screenshot must be set manually in App Store Connect (Apple API limitation).')
            ->columns([
                Tables\Columns\TextColumn::make('product_id')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('grant_type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pack' => 'info',
                        'theme_pack' => 'warning',
                        'supporter' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('price')
                    ->suffix(fn ($record) => ' '.$record->currency),
                Tables\Columns\TextColumn::make('apple_state')
                    ->label('Synced to Apple')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'synced' => 'success',
                        'syncing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'synced' => 'Synced (needs price)',
                        'syncing' => 'Syncing...',
                        'failed' => 'Failed',
                        default => 'Not synced',
                    }),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn (Product $record) => $this->syncToAppleAndNotify($record, 'created')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(fn (Product $record) => $this->syncToAppleAndNotify($record, 'updated')),
                Tables\Actions\Action::make('sync')
                    ->label('Sync to Apple')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (Product $record) => $this->syncToAppleAndNotify($record, 'sync')),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /**
     * Sync a product to App Store Connect and show a Filament notification
     * with the result.
     */
    private function syncToAppleAndNotify(Product $product, string $context): void
    {
        $service = app(ProductSyncService::class);
        $result = $service->sync($product);

        if ($result['success']) {
            Notification::make()
                ->success()
                ->title("Product {$context} — synced to App Store Connect")
                ->body($result['message'])
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->warning()
                ->title("Product {$context} — sync failed")
                ->body($result['message'].' (Product is saved locally; you can retry via the Sync action or the `iap:sync` command.)')
                ->persistent()
                ->send();
        }
    }
}
