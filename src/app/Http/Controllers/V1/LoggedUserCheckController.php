<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\People;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoggedUserCheckController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $token = $request->header('token');
        if (!$token || !Hash::check($token, config('personalaccesstoken.auth_key'))) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $idNumber = $request->idNumber; // idNumber is a NIK or NIP
        $userId = People::select('PeopleId')
            ->where('NIK', $idNumber)
            ->orWhere('NIP', $idNumber)
            ->first();

        if (!$userId) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        $hasLogged = PersonalAccessToken::where('tokenable_id', $userId?->PeopleId)->exists();
        if (!$hasLogged) {
            return response()->json([
                'message' => 'User has never logged in'
            ], 412);
        }

        return response()->json([
            'message' => 'User has already logged in',
        ], 200);
    }
}
