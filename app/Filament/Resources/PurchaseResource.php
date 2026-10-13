<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Purchase;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Game Data';

    protected static ?int $navigationSort = 6;

    private const STATUSES = [
        'pending' => 'Pending',
        'verified' => 'Verified',
        'refunded' => 'Refunded',
        'failed' => 'Failed',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('status')
                ->options(self::STATUSES)
                ->required()
                // Select validerar inte mot sina options; kolumnen är en enum.
                ->rules([Rule::in(array_keys(self::STATUSES))]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.display_name')
                    ->default(fn ($record) => $record->user->name)
                    ->label('Player')
                    ->searchable(['users.display_name', 'users.name']),
                Tables\Columns\TextColumn::make('game.name')->sortable(),
                Tables\Columns\TextColumn::make('product_id')->searchable(),
                Tables\Columns\TextColumn::make('store'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'verified',
                        'danger' => fn ($state) => in_array($state, ['refunded', 'failed']),
                    ]),
                Tables\Columns\TextColumn::make('purchased_at')->dateTime()->sortable(),
            ])
            ->defaultSort('purchased_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'verified' => 'Verified',
                        'refunded' => 'Refunded',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('store')
                    ->options([
                        'apple' => 'Apple',
                        'google' => 'Google',
                        'web' => 'Web',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
