<?php

use App\Http\Controllers\Api\V1\AchievementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DailyContentController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\GameEventController;
use App\Http\Controllers\Api\V1\GameSaveController;
use App\Http\Controllers\Api\V1\GlosisScanController;
use App\Http\Controllers\Api\V1\GlosisTtsController;
use App\Http\Controllers\Api\V1\InterestSignupController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\OwnershipController;
use App\Http\Controllers\Api\V1\PlayerStatsController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RemoteConfigController;
use App\Http\Controllers\Api\V1\RevenueCatWebhookController;
use App\Http\Controllers\Api\V1\StreakController;
use App\Http\Middleware\ApiVersion;
use App\Http\Middleware\ResolveGame;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(ApiVersion::class.':1')->group(function () {
    // Auth (public)
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/anonymous', [AuthController::class, 'anonymous']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    // Auth (authenticated, allows anonymous upgrade)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'update']);
        Route::delete('/auth/me', [AuthController::class, 'destroy']);
    });

    // Games (public)
    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game:slug}', [GameController::class, 'show']);

    // Remote Config (public, no auth required)
    Route::middleware(ResolveGame::class)
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            Route::get('/games/{game}/config', [RemoteConfigController::class, 'index']);
        });

    // Intresseanmälan (publik). Bekräftelse och avregistrering sker via
    // länken i mailet; token är slumpad och lagras bara som hash.
    Route::middleware([ResolveGame::class, 'throttle:interest'])
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            Route::post('/games/{game}/interest', [InterestSignupController::class, 'store']);
        });
    Route::get('/interest/{token}/confirm', [InterestSignupController::class, 'confirm'])
        ->middleware('throttle:30,1')->name('interest.confirm');
    Route::get('/interest/{token}/unsubscribe', [InterestSignupController::class, 'unsubscribeForm'])
        ->middleware('throttle:30,1')->name('interest.unsubscribe');
    Route::post('/interest/{token}/unsubscribe', [InterestSignupController::class, 'unsubscribe'])
        ->middleware('throttle:30,1')->name('interest.unsubscribe.store');

    // Glosis: fota läxan. Inga konton; rätten bevisas med ett StoreKit-köp
    // av Guld i anropet. Gränsen per köp och dygn ligger i controllern.
    Route::middleware([ResolveGame::class, 'throttle:glosis-scan'])
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            Route::post('/games/{game}/scan', GlosisScanController::class)->where('game', 'glosis');
        });

    // Glosis studioröst. Samma köpbevis som skanningen; gränserna för nya ord
    // och månadsbudgeten ligger i StudioVoice. Ljudet hämtas med en signerad
    // adress (24 h) från prepare; signaturen är behörigheten. Ljudet har en egen,
    // högre gräns i stället för den globala (60/min), eftersom en lektion hämtar
    // upp till 40 filer och en skolklass delar IP.
    Route::middleware([ResolveGame::class, 'throttle:glosis-tts'])
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            Route::post('/games/{game}/tts/prepare', [GlosisTtsController::class, 'prepare'])->where('game', 'glosis');
        });
    Route::get('/games/glosis/tts/audio/{hash}', [GlosisTtsController::class, 'audio'])
        ->where('hash', '[0-9a-f]{64}')
        ->middleware(['signed:relative', 'throttle:glosis-tts-audio'])
        ->withoutMiddleware('throttle:60,1')
        ->name(\App\Services\Glosis\StudioVoice::AUDIO_ROUTE);

    // RevenueCat webhooks (no auth — signature verified in controller)
    Route::middleware(ResolveGame::class)
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            Route::post('/webhooks/revenuecat/{game}', [RevenueCatWebhookController::class, 'handle']);
        });

    // Game-scoped endpoints (authenticated)
    Route::middleware(['auth:sanctum', TrackLastSeen::class, ResolveGame::class])
        ->prefix('/games/{game}')
        ->withoutMiddleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
        ->group(function () {
            // Leaderboards
            Route::get('/leaderboards/{type}/me', [LeaderboardController::class, 'me']);
            Route::get('/leaderboards/{type}', [LeaderboardController::class, 'index']);
            Route::get('/leaderboards/{type}/{periodKey}', [LeaderboardController::class, 'show']);
            Route::post('/leaderboards/{type}', [LeaderboardController::class, 'store'])
                ->middleware('throttle:score-submit');

            // Achievements
            Route::get('/achievements', [AchievementController::class, 'index']);
            Route::get('/achievements/me', [AchievementController::class, 'me']);
            Route::post('/achievements', [AchievementController::class, 'store']);

            // Saves
            Route::get('/saves', [GameSaveController::class, 'index']);
            Route::get('/saves/{key}', [GameSaveController::class, 'show']);
            Route::put('/saves/{key}', [GameSaveController::class, 'update']);
            Route::delete('/saves/{key}', [GameSaveController::class, 'destroy']);

            // Ownership (server-authoritative)
            Route::get('/ownership', [OwnershipController::class, 'show']);

            // Daily Content
            Route::get('/daily/{poolKey}', [DailyContentController::class, 'show']);
            Route::get('/daily/{poolKey}/{date}', [DailyContentController::class, 'showDate']);

            // Streaks
            Route::get('/streaks/{key}', [StreakController::class, 'show']);
            Route::post('/streaks/{key}/record', [StreakController::class, 'record']);

            // Player Stats
            Route::get('/stats/me', [PlayerStatsController::class, 'me']);

            // Events
            Route::get('/events', [GameEventController::class, 'index']);
            Route::get('/events/{slug}', [GameEventController::class, 'show']);
        });

    // Purchases (authenticated)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/purchases', [PurchaseController::class, 'index']);
    });
});
