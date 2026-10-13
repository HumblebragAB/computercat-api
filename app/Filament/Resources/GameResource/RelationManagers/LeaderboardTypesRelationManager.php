<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Support\ConfigRules\LeaderboardTypeRules;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LeaderboardTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'leaderboardTypes';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->maxLength(50)
                ->rules(fn (?Model $record) => LeaderboardTypeRules::rules($this->getOwnerRecord(), $record)['slug']),
            Forms\Components\TextInput::make('name')->required()->maxLength(100),
            Forms\Components\Select::make('sort_direction')
                ->options(['asc' => 'Ascending (lower is better)', 'desc' => 'Descending (higher is better)'])
                ->required(),
            Forms\Components\TextInput::make('score_label')->required()->maxLength(50),
            Forms\Components\Select::make('period')
                ->options(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'all_time' => 'All Time'])
                ->required(),
            Forms\Components\TextInput::make('max_entries_per_period')->numeric()->default(100)
                ->rules(fn () => LeaderboardTypeRules::rules($this->getOwnerRecord(), null)['max_entries_per_period']),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('sort_direction'),
                Tables\Columns\TextColumn::make('period'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
