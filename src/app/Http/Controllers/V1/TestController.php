<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Classification;

class TestController extends Controller
{
    /**
     * Document types list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $result = Classification::where('ClName', $request->input('ClName'))->get();
        return json_encode($result);
    }

    public function testPost(Request $request)
    {
        $classification = new Classification;
 
        $classification->ClName = $request->input('ClName');
        $classification->ClCode = $request->input('ClCode');
        $classification->save();

        return json_encode($classification);
    }
}
