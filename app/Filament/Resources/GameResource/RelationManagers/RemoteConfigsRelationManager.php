<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Support\ConfigRules\RemoteConfigRules;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RemoteConfigsRelationManager extends RelationManager
{
    protected static string $relationship = 'remoteConfigs';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')
                ->required()
                ->maxLength(100)
                ->rules(fn (?Model $record) => RemoteConfigRules::rules($this->getOwnerRecord(), $record, null)['key'])
                ->helperText('Config key (e.g., "feature_dark_mode", "max_hints")'),
            Forms\Components\Textarea::make('value')
                ->required()
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                    if ($error = RemoteConfigRules::valueError($get('value_type'), $value)) {
                        $fail($error);
                    }
                })
                ->helperText('The config value (will be cast based on value_type)'),
            Forms\Components\Select::make('value_type')
                ->options([
                    'string' => 'String',
                    'int' => 'Integer',
                    'bool' => 'Boolean',
                    'json' => 'JSON',
                ])
                ->required()
                ->live()
                ->default('string'),
            Forms\Components\Textarea::make('description')
                ->helperText('Internal note about what this config controls'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->searchable(),
                Tables\Columns\TextColumn::make('value')->limit(50),
                Tables\Columns\TextColumn::make('value_type')->badge(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
