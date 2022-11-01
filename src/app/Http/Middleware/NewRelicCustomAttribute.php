<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class NewRelicCustomAttribute
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->check()) {
            if (extension_loaded('newrelic')) {
                newrelic_add_custom_parameter('session.PeopleId', $request->user()->PeopleId);
            }
        }

        return $next($request);
    }
}
