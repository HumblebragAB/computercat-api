<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Medlemmar (MCP)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('role')->label('Roll'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    // Bara staff kan vara medlemmar; spelare finns i samma tabell.
                    ->recordSelectOptionsQuery(fn (Builder $q) => $q->where('is_staff', true)->where('is_banned', false))
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect()->rules([
                            // Servern avgör, inte dropdownen: ett id utanför listan avvisas.
                            fn () => function (string $attribute, $value, \Closure $fail) {
                                $user = User::find($value);
                                if (! $user || ! $user->isStaff()) {
                                    $fail('Bara staff-konton kan vara medlemmar.');
                                }
                            },
                        ]),
                        Forms\Components\Select::make('role')->options(['owner' => 'Ägare', 'member' => 'Medlem'])->default('member')->required(),
                    ]),
            ])
            ->actions([Tables\Actions\DetachAction::make()]);
    }
}
