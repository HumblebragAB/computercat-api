<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DailyContentPoolsRelationManager extends RelationManager
{
    protected static string $relationship = 'dailyContentPools';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('pool_key')
                ->required()
                ->maxLength(100)
                ->helperText('Unique key for this content pool (e.g., "daily-puzzle", "word-of-the-day")'),
            Forms\Components\Textarea::make('content')
                ->required()
                ->helperText('JSON array of content items (max 5000)')
                ->rules([
                    fn () => function (string $attribute, $value, Closure $fail) {
                        $decoded = json_decode((string) $value, true);
                        if (! is_array($decoded) || ! array_is_list($decoded)) {
                            $fail('Content must be a valid JSON array, e.g. ["a", "b"] or [{"id": 1}].');
                        } elseif (count($decoded) > 5000) {
                            $fail('Content can hold at most 5000 items.');
                        }
                    },
                ])
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                ->dehydrateStateUsing(fn ($state) => json_decode($state, true))
                ->columnSpanFull(),
            Forms\Components\DatePicker::make('starts_at')
                ->helperText('Pool becomes active from this date. Leave empty for immediate.'),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\Toggle::make('random_access')
                ->label('Random access')
                ->default(false)
                ->helperText('Serve random items via /pools/{key}/items. Any player can then read the whole pool, so leave off for daily pools.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pool_key'),
                Tables\Columns\TextColumn::make('starts_at')->date(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\IconColumn::make('random_access')->boolean()->label('Random'),
                Tables\Columns\TextColumn::make('content')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) . ' items' : '—')
                    ->label('Items'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
