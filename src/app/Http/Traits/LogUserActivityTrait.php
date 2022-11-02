<?php

namespace App\Http\Traits;

use App\Models\LogUserActivity;

/**
 * Save log activity by query & mutation.
 */
trait LogUserActivityTrait
{
    public function saveLogActivity($request)
    {
        /**
         * This log will be turn off/remove later because it's not necessary.
         * We need doing kafka setup on production and migration log with jds data team already completed.
         */
        $doLogging = true;
        if ($request['device'] == 'mobile') {
            $request = $this->getActionName($request);
            if ($request['action'] == '__schema') { //handle for playground graphql
                $doLogging = false;
            }
        }
        if ($doLogging) {
            $log            = new LogUserActivity();
            $log->people_id = $request['people_id'];
            $log->device    = $request['device'];
            $log->type      = $request['type'] ?? null;
            $log->action    = $request['action'];
            $log->save();
            return $log;
        }
        return false;
    }

    public function getActionName($request)
    {
        $request['action'] = str_replace('"', '', $request['action']);
        //identify request['action'] is query / mutation (graphql)
        $methodTemp  = ltrim($request['action']);
        $methodTemp  = strtok($methodTemp, " ");
        $method = (str_contains($methodTemp, 'mutation')) ? 'mutation' : 'query';
        //identify field in schema
        $action = explode('{', $request['action']);
        $action = explode('(', (($methodTemp == '{') ? $action[0] : $action[1]));
        $action = trim(str_replace('\r\n', '', str_replace('__typename', '', trim($action[0]))));
        $request['type']    = $method;
        $request['action']  = $action;

        return $request;
    }
}
