<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\TransientToken;
use Symfony\Component\HttpFoundation\Response;

// Grind för MCP och OAuth-godkännandet. Kontrolleras vid varje anrop, så att
// en utfärdad token slutar fungera när flaggan tas bort eller kontot bannas.
class EnsureStaff
{
    // Med parametern "guests" släpps oinloggade vidare (authorize-routes, där
    // Passport själv skickar gäster till login). Utan den nekas även gäster.
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (! $user && $mode === 'guests') {
            return $next($request);
        }

        if (! $user || ! $user->isStaff()) {
            abort(403);
        }

        // Passports TokenGuard godtar också cookien laravel_token, som ger en
        // TransientToken där can() är sant för alla scopes. Vi utfärdar aldrig
        // den cookien (POST /oauth/token/refresh är inte registrerad, ingen
        // CreateFreshApiToken), men om den ändå dyker upp ska den inte kunna
        // ersätta en token som staff godkänt via OAuth.
        if ($user instanceof StaffUser && $user->token() instanceof TransientToken) {
            abort(403);
        }

        return $next($request);
    }
}
