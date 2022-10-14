<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentSignature;
use Illuminate\Http\Request;

class DocumentSignatureFileController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  String $type
     * @param  String $id
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id)
    {
        $document = DocumentSignature::find($id);
        $file = $document->getUrlPublicAttribute();
        if ($document && $file) {
            $filename = $document->file;
            $tempFile = tempnam(sys_get_temp_dir(), $filename);
            copy($file, $tempFile);
            return response()->download($tempFile, $filename);
        } else {
            return response()->json([
                'message' => 'File not found'
            ], 404);
        }
    }
}
