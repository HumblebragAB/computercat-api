<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Support\ConfigRules\AchievementRules;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AchievementDefinitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'achievementDefinitions';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->maxLength(50)
                ->rules(fn (?Model $record) => AchievementRules::rules($this->getOwnerRecord(), $record)['slug']),
            Forms\Components\TextInput::make('name')->required()->maxLength(100),
            Forms\Components\TextInput::make('description')->required()->maxLength(255),
            Forms\Components\TextInput::make('icon')->required()->maxLength(20),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0)
                ->rules(fn () => AchievementRules::rules($this->getOwnerRecord(), null)['sort_order']),
            Forms\Components\Toggle::make('is_secret')->default(false),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('icon'),
                Tables\Columns\TextColumn::make('slug'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\IconColumn::make('is_secret')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
