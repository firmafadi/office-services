<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\MasterClassified;
use Illuminate\Http\Request;

class DocumentClassifiedController extends Controller
{
    /**
     * Document classified types list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $limit = $request->input('limit', 20);
        $records = MasterClassified::select('SifatId as id', 'SifatName as name')->paginate($limit);
        return $records;
    }
}
