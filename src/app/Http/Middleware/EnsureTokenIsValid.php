<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();
            $decodedToken = parseJWTToken($token);

            $request['token'] = $decodedToken;

            return $next($request);
        } catch (\Throwable $th) {
            return abort(403, $th->getMessage());
        }
    }
}
