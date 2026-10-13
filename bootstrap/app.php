<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
        // Passport::ignoreRoutes() i AppServiceProvider; se routes/passport.php.
        then: function () {
            require __DIR__.'/../routes/passport.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('60,1');
        $middleware->statefulApi();

        // Avregistreringen postas från API:ts egen sida, och statefulApi()
        // lägger CSRF-kontroll på anrop från den egna domänen. Den slumpade
        // token i adressen är behörigheten; en CSRF-token tillför inget.
        //
        // Glosis fotoskanning och studioröst: Capacitor på Android har origin https://localhost,
        // som Sanctum räknar som "stateful" och då kräver CSRF. Anropet har
        // ingen session; köpbeviset i anropet är behörigheten. Samma sak för
        // gratisnivåns App Attest-endpoints, där Apples intyg är behörigheten.
        $middleware->validateCsrfTokens(except: [
            'api/v1/interest/*/unsubscribe', 'api/v1/games/glosis/scan', 'api/v1/games/glosis/tts/prepare',
            'api/v1/games/glosis/attest/challenge', 'api/v1/games/glosis/attest/register',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // MCP-klienter (claude.ai) skickar ofta Accept: text/event-stream. Utan /mcp här
        // blir en saknad token en redirect till login i stället för 401, och då hittar
        // klienten aldrig OAuth-discoveryn i WWW-Authenticate.
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*', 'mcp', 'mcp/*') || $request->expectsJson();
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        // Glosis-appens kontrakt för 429 gäller både den globala API-gränsen
        // och glosis-scan-, glosis-tts- och glosis-attest-gränserna per IP.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/v1/games/glosis/scan', 'api/v1/games/glosis/tts/prepare', 'api/v1/games/glosis/attest/challenge', 'api/v1/games/glosis/attest/register')) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return response()->json([
                    'error' => 'rate_limited',
                    'message' => 'För många försök just nu. Vänta en stund och försök igen.',
                    'retry_after' => $retryAfter,
                ], 429, $e->getHeaders());
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    })->create();
