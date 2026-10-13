<?php

use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

// Passports routes, registrerade här i stället för av paketet
// (Passport::ignoreRoutes() i AppServiceProvider). Kopia av
// vendor/laravel/passport/routes/web.php (v13) med samma namn, prefix och
// middleware, med tre avvikelser:
// - authorize, approve och deny kräver staff (EnsureStaff:guests; gäster
//   släpps vidare så att Passport skickar dem till login).
// - Device code-flödet (/oauth/device*) registreras inte. Grant-typen är också
//   avstängd (Passport::$deviceCodeGrantEnabled i AppServiceProvider).
// - POST /oauth/token/refresh (TransientTokenController) registreras inte. Den
//   ger en inloggad web-användare en laravel_token-cookie som Passports
//   TokenGuard godtar på /mcp, förbi OAuth-godkännandet.
// JSON-API:t (Passport::$registersJsonApiRoutes) är avstängt som default i v13
// och registreras inte heller.
Route::group([
    'as' => 'passport.',
    'prefix' => config('passport.path', 'oauth'),
    'namespace' => 'Laravel\Passport\Http\Controllers',
    'middleware' => config('passport.middleware', []),
], function () {
    Route::post('/token', [
        'uses' => 'AccessTokenController@issueToken',
        'as' => 'token',
        'middleware' => 'throttle',
    ]);

    Route::get('/authorize', [
        'uses' => 'AuthorizationController@authorize',
        'as' => 'authorizations.authorize',
        'middleware' => ['web', EnsureStaff::class.':guests'],
    ]);

    $guard = config('passport.guard', null);

    Route::middleware(['web', $guard ? 'auth:'.$guard : 'auth', EnsureStaff::class.':guests'])->group(function () {
        Route::post('/authorize', [
            'uses' => 'ApproveAuthorizationController@approve',
            'as' => 'authorizations.approve',
        ]);

        Route::delete('/authorize', [
            'uses' => 'DenyAuthorizationController@deny',
            'as' => 'authorizations.deny',
        ]);
    });
});
