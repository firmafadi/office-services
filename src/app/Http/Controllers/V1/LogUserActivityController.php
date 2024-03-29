<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\LogUserActivityTrait;
use Illuminate\Http\Request;

class LogUserActivityController extends Controller
{
    use LogUserActivityTrait;

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        if (config('sikd.mysql_user_log_activity') == true) {
            $addLog = $this->saveLogActivity($request);

            if (!$addLog) {
                return response()->json([
                    'message' => 'Failed insert log user activity',
                    'status' => false,
                ], 404);
            }

            return response()->json([
                'message' => 'Success insert log user activity',
                'status' => true,
            ], 200);
        }
    }
}
