<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->bearerToken();
        $expected = config('services.internal_api.key');

        if (! $provided || ! hash_equals((string) $expected, (string) $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}


