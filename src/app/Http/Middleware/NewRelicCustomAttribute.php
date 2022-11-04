<?php

namespace App\Http\Middleware;

use App\Http\Traits\LogUserActivityTrait;
use Closure;
use Illuminate\Http\Request;

class NewRelicCustomAttribute
{

    use LogUserActivityTrait;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (extension_loaded('newrelic')) {
            $request['action'] = $request->input('query');
            $transactionName = $this->getActionName($request);

            newrelic_name_transaction($transactionName['action']);
            newrelic_add_custom_parameter('graphql.request_body', $request->getContent());
            if (auth()->check()) {
                newrelic_add_custom_parameter('session.PeopleId', $request->user()->PeopleId);
            }
        }

        return $next($request);
    }
}
