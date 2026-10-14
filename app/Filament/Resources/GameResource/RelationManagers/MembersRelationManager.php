<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Medlemmar (MCP)';

    private const ROLES = ['owner' => 'Ägare', 'member' => 'Medlem'];

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
                    // Bara staff kan vara medlemmar; spelare finns i samma tabell. Parametern måste
                    // heta $query: Filament injicerar på namn, annars fås en tom Builder.
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->where('is_staff', true)->where('is_banned', false))
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
                        // Select validerar inte mot sina options; rollen avgörs på servern.
                        Forms\Components\Select::make('role')->options(self::ROLES)->default('member')->required()
                            ->rules(['required', Rule::in(array_keys(self::ROLES))]),
                    ]),
            ])
            ->actions([Tables\Actions\DetachAction::make()]);
    }
}
