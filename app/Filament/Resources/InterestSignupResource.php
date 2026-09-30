<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InterestSignupResource\Pages;
use App\Models\InterestSignup;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InterestSignupResource extends Resource
{
    protected static ?string $model = InterestSignup::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Game Data';

    protected static ?string $navigationLabel = 'Interest signups';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('game.name')->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('language')
                    ->formatStateUsing(fn (string $state) => InterestSignup::SPRAK[$state] ?? $state)
                    ->badge(),
                Tables\Columns\IconColumn::make('confirmed_at')->label('Confirmed')
                    ->boolean()->getStateUsing(fn ($record) => $record->confirmed_at !== null),
                Tables\Columns\TextColumn::make('confirmation_sent_at')->label('Mail sent')->since()
                    ->placeholder('Not sent'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('game')->relationship('game', 'name'),
                Tables\Filters\SelectFilter::make('language')->options(InterestSignup::SPRAK),
                Tables\Filters\TernaryFilter::make('confirmed')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('confirmed_at'),
                        false: fn (Builder $q) => $q->whereNull('confirmed_at'),
                    ),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInterestSignups::route('/'),
        ];
    }
}
