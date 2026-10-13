<?php

namespace App\Models\Concerns;

// Gemensam för User (web-guarden: panelen och /oauth/authorize) och StaffUser
// (guarden mcp), så att EnsureStaff ger samma svar oavsett vilken modell
// $request->user() är. Flaggorna sätts bara av computercat:staff och får
// aldrig läggas i $fillable. Modellerna castar is_staff, is_platform_admin och
// is_banned till boolean.
trait HasStaffFlags
{
    public function isStaff(): bool
    {
        return (bool) $this->is_staff && ! $this->is_banned;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isStaff() && (bool) $this->is_platform_admin;
    }
}
