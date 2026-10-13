<?php

namespace App\Filament\Resources;

use App\Filament\Resources\McpAuditLogResource\Pages;
use App\Models\McpAuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

// Skrivskyddad vy över revisionsloggen. Inga skapa/ändra/radera-vägar finns.
class McpAuditLogResource extends Resource
{
    protected static ?string $model = McpAuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'MCP';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit log entry';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('user.email')->placeholder('-'),
            TextEntry::make('game.slug')->placeholder('-'),
            TextEntry::make('tool'),
            TextEntry::make('outcome')->badge(),
            TextEntry::make('arguments')
                ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->fontFamily('mono')
                ->extraAttributes(['style' => 'white-space: pre-wrap'])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('user.email')->searchable()->placeholder('-'),
                Tables\Columns\TextColumn::make('game.slug')->searchable()->placeholder('-'),
                Tables\Columns\TextColumn::make('tool')->searchable(),
                Tables\Columns\TextColumn::make('outcome')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ok' => 'success',
                        'denied', 'error' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')->options([
                    'ok' => 'ok', 'validation_error' => 'validation_error', 'denied' => 'denied', 'error' => 'error',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMcpAuditLogs::route('/'),
        ];
    }
}
