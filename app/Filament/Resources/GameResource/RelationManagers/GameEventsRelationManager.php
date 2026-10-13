<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Support\ConfigRules\GameEventRules;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GameEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'gameEvents';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')
                ->required()
                ->maxLength(100)
                ->rules(fn (?Model $record) => GameEventRules::rules($this->getOwnerRecord(), $record)['slug'])
                ->helperText('URL-friendly identifier (e.g., "spring-2026-challenge")'),
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description'),
            Forms\Components\Select::make('event_type')
                ->options([
                    'leaderboard' => 'Leaderboard',
                    'challenge' => 'Challenge',
                    'seasonal' => 'Seasonal',
                ])
                ->required()
                // Select validerar inte själv mot sina options; samma regel som MCP.
                ->rules(fn () => GameEventRules::rules($this->getOwnerRecord(), null)['event_type']),
            Forms\Components\DateTimePicker::make('starts_at')
                ->required(),
            Forms\Components\DateTimePicker::make('ends_at')
                ->required()
                ->after('starts_at'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('event_type')->badge(),
                Tables\Columns\TextColumn::make('starts_at')->dateTime(),
                Tables\Columns\TextColumn::make('ends_at')->dateTime(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
