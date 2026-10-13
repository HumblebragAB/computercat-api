<?php

namespace App\Models;

use App\Models\Concerns\HasStaffFlags;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

// Samma rad i users som User, men med Passports tokens. Används bara av guarden mcp.
// Ärver inte User: Sanctums och Passports HasApiTokens har inkompatibla signaturer
// (createToken, withAccessToken, egenskapen $accessToken), och PHP vägrar då ladda
// klassen även om barnets trait-metoder skulle vinna.
class StaffUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasStaffFlags;

    protected $table = 'users';

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_banned' => 'boolean',
            'is_staff' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    // Polymorfa rader (t.ex. oauth_clients.owner_type) ska matcha User.
    public function getMorphClass(): string
    {
        return User::class;
    }
}
