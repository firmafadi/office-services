<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentSignatureType;
use Illuminate\Http\Request;

class EsignDocumentTypeController extends Controller
{
    /**
     * Esign document types list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $limit = $request->input('limit', 20);
        $records = DocumentSignatureType::select('id', 'document_type as name')->paginate($limit);
        return $records;
    }
}
