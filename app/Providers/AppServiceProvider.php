<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Utan egen transporter bygger skannern en Guzzle med timeout. Bind
        // uttryckligen så att containern aldrig skickar in en annan PSR-18-klient.
        $this->app->bind(\App\Services\Glosis\HomeworkScanner::class, fn () => new \App\Services\Glosis\HomeworkScanner);

        // Passports routes registreras i routes/passport.php (staff-krav på authorize,
        // utan device code och /oauth/token/refresh). Device code-granten stängs av
        // också i auktoriseringsservern; Passport har ingen metod för det.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;
    }

    public function boot(): void
    {
        // MCP/OAuth: kortlivade access-tokens, refresh-token i 30 dagar.
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Passport levererar ingen godkännandevy; laravel/mcp har en men registrerar den inte.
        Passport::authorizationView('mcp::authorize');

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Tokenförnyelse har egna gränser, skilda från auth (anonymous/login)
        // så att en skolklass som skapar konton bakom samma IP inte stoppar
        // förnyelsen. Den lätta IP-gränsen ligger före auth:sanctum, gränsen
        // per användare efter.
        RateLimiter::for('auth-refresh-ip', function (Request $request) {
            return Limit::perMinute(120)->by('ip:'.$request->ip());
        });
        RateLimiter::for('auth-refresh', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(30)->by($user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });

        // Sanctum-guarden släpper aldrig in en bannad användare, även om ett
        // token överlevt (raderingen i User sker bara via modellhändelser).
        // Gäller bara Sanctum; MCP/Passport (StaffUser) har EnsureStaff.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            return $isValid && ! ($accessToken->tokenable instanceof User && $accessToken->tokenable->is_banned);
        });

        // Första gången ett förnyat token används kortas tokenet det ersatte
        // till högst 10 minuter, så att parallella anrop med det gamla hinner
        // klart. Eventet skickas bara för giltiga token, före last_used_at
        // sätts, så null betyder första användningen.
        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event) {
            $token = $event->token;

            if (! $token->refreshed_from_id || $token->last_used_at !== null) {
                return;
            }

            $grace = now()->addMinutes(10);
            Sanctum::$personalAccessTokenModel::whereKey($token->refreshed_from_id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $grace))
                ->update(['expires_at' => $grace]);
        });

        // Intresseanmälan är publik och utan inloggning. Gränsen per adress
        // är det egentliga skyddet mot att spamma någon. Gränsen per IP är
        // generös med flit: spelens webbplatser (glosis.se) anropar
        // server-till-server, så alla deras besökare delar en IP, och varje
        // webbplats begränsar sina besökare själv.
        RateLimiter::for('interest', function (Request $request) {
            return [
                Limit::perMinute(60)->by($request->ip()),
                Limit::perHour(3)->by('interest:'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        // Glosis fotoskanning: 60 per timme och IP, utöver gränsen per köp
        // och dygn i GlosisScanController. 429-svarets form sätts i
        // bootstrap/app.php (gäller även den globala API-gränsen).
        RateLimiter::for('glosis-scan', function (Request $request) {
            return Limit::perHour(60)->by('glosis-scan:'.$request->ip());
        });

        // Glosis studioröst: prepare anropas en gång per lektion (högst 40 ord).
        // Gränserna för nya ord och budgeten ligger i StudioVoice.
        RateLimiter::for('glosis-tts', function (Request $request) {
            return Limit::perMinute(30)->by('glosis-tts:'.$request->ip());
        });

        // Ljudfilerna (signerade adresser). Ersätter den globala API-gränsen.
        RateLimiter::for('glosis-tts-audio', function (Request $request) {
            return Limit::perMinute(600)->by('glosis-tts-audio:'.$request->ip());
        });

        // Glosis App Attest-challenges: en per gratisförfrågan (ett pass ger ungefär fyra).
        // En skolklass delar IP, därför generöst. Ersätter den globala API-gränsen.
        RateLimiter::for('glosis-attest-challenge', function (Request $request) {
            return Limit::perMinute(120)->by('glosis-attest-ch:'.$request->ip());
        });

        // Nya App Attest-nycklar: en per installation. Det finns ingen proxy framför (nginx terminerar
        // TLS), så ip() är klientens adress; en skola bakom NAT delar den. 60/h räcker för en klass som
        // installerar samtidigt. Gränsen hindrar inte en angripare med många IP-adresser; de verkliga
        // gränserna är taken per IP och de globala gratistaken (beslut 2026-10-12). Ersätter den globala
        // API-gränsen.
        RateLimiter::for('glosis-attest-register', function (Request $request) {
            return Limit::perHour(60)->by('glosis-attest-reg:'.$request->ip());
        });

        // MCP:s discovery (.well-known) och dynamiska klientregistrering (POST /oauth/register)
        // är oautentiserade. Egen nyckel: throttle:10,1 utan prefix delar räknare med
        // API-gränsen och /oauth/token för samma IP.
        RateLimiter::for('mcp-oauth', function (Request $request) {
            return Limit::perMinute(10)->by('mcp-oauth:'.$request->ip());
        });

        // /mcp per inloggad användare. Ligger efter auth:mcp men före EnsureStaff,
        // så att även nekade anrop räknas innan de skriver revisionsloggen.
        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(120)->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        // Byggguiden (publik, bara läsning). Ett nytt spelrepo behöver två anrop
        // (manifest och package-lock.json); resten går via MCP.
        RateLimiter::for('build-guide', function (Request $request) {
            return Limit::perMinute(30)->by('build-guide:'.$request->ip());
        });

        RateLimiter::for('score-submit', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Matcher: per användar-id (vägarna ligger efter auth:sanctum). Ersätter den globala
        // API-gränsen, eftersom pollen går ungefär en gång i sekunden. Egna prefix, så att
        // räknarna inte delas med andra gränser.
        $matchKey = fn (string $name, Request $request) => $name.':'.($request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip());
        RateLimiter::for('match-create', fn (Request $request) => Limit::perMinute(10)->by($matchKey('match-create', $request)));
        RateLimiter::for('match-join', fn (Request $request) => [
            Limit::perMinute(10)->by($matchKey('match-join', $request)),
            Limit::perMinute(30)->by('match-join-ip:'.$request->ip()),
        ]);
        // Misslyckade join (invite_expired), räknade av MatchController och inte av throttle-
        // middleware: lyckade join ska inte förbruka något. En inbjudningskod (invite => code)
        // har 31^4 ≈ 923 000 värden och lever 15 min, så den går i princip att gissa. Anonyma
        // konton är gratis, därför gränsen per IP: 60 missar i timmen ger i snitt ~7 700 timmar
        // för att träffa en viss kod. Med många öppna matcher samtidigt träffar en gissare någon
        // match fortare, men kan inte välja vilken. Värsta fallet är litet: en främling går med
        // i en vänskapsmatch och ser bara spelarnamnen; ingen annan data nås via en match.
        RateLimiter::for('match-join-fail', fn (Request $request) => [
            Limit::perHour(20)->by('match-join-fail:'.($request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip())),
            Limit::perHour(60)->by('match-join-fail-ip:'.$request->ip()),
        ]);
        RateLimiter::for('match-poll', fn (Request $request) => Limit::perMinute(120)->by($matchKey('match-poll', $request)));
        RateLimiter::for('match-action', fn (Request $request) => Limit::perMinute(60)->by($matchKey('match-action', $request)));
    }
}
