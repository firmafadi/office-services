<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentSignature;
use Illuminate\Http\Request;

class EsignDocumentCheckStatusController extends Controller
{
    /**
     * Check the status of the list of documents
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $ids = explode(',', $request->input('ids'));
        $records = DocumentSignature::select('id', 'file', 'status as status_id')
            ->whereIn('id', $ids)
            ->get();
        return $records;
    }
}
