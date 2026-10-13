<?php

use App\Http\Middleware\EnsureStaff;
use App\Mcp\ComputercatServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// Laddas av McpServiceProvider utan middleware-grupp: ingen session, ingen CSRF.

// Discovery (.well-known) och dynamisk klientregistrering (POST /oauth/register).
// Registreringen är oautentiserad, därför en gräns per IP (limitern mcp-oauth i
// AppServiceProvider, 10/min). Passports egna routes (/oauth/token,
// /oauth/authorize) registreras inte här och påverkas inte.
Route::middleware('throttle:mcp-oauth')->group(fn () => Mcp::oauthRoutes());

// EnsureStaff kontrollerar flaggan vid varje anrop: en token slutar fungera
// direkt när staff tas bort eller kontot bannas.
Mcp::web('/mcp', ComputercatServer::class)
    ->middleware(['auth:mcp', CheckToken::using('mcp:use'), EnsureStaff::class]);
