<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\CustomException;
use App\Http\Controllers\Controller;
use App\Models\People;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;

class ListLoggedUsers extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || $authHeader != 'Basic ' . config('personalaccesstoken.auth_key')) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $idNumber = $request->idNumber; // idNumber is a NIK or NIP
        $userId = People::select('PeopleId')
            ->where('NIK', $idNumber)
            ->orWhere('NIP', $idNumber)
            ->first();

        $hasLogged = PersonalAccessToken::where('tokenable_id', $userId?->PeopleId)->exists();
        if (!$hasLogged) {
            return response()->json([
                'message' => 'No logged user found'
            ], 404);
        }

        return response()->json([
            'message' => 'User has logged',
        ], 200);
    }
}
