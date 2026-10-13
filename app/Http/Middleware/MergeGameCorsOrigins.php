<?php

namespace App\Http\Middleware;

use App\Support\GameCorsOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Måste ligga före den globala HandleCors, annars ser preflight (OPTIONS)
// aldrig spelens origins. HandleCors läser config vid varje request.
class MergeGameCorsOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*') && $request->headers->has('Origin')) {
            config(['cors.allowed_origins' => array_values(array_unique(array_merge(
                config('cors.allowed_origins', []),
                GameCorsOrigins::all(),
            )))]);
        }

        return $next($request);
    }
}
