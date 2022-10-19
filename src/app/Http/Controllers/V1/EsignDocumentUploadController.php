<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEsignDocumentUploadRequest;
use App\Models\DocumentSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class EsignDocumentUploadController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function upload(StoreEsignDocumentUploadRequest $request)
    {
        $transfer = $this->doTransferFile($request);
    }

    protected function doTransferFile($request)
    {
        $fileName = $request->file('file')->getClientOriginalName();
        $request->file('file')->storeAs('esign_document', $fileName);

        $fileUpload = Storage::disk('esign_document')->get($fileName);

        $documentRequest = [
            'document_esign_type_id' => $request->document_esign_type_id
        ];

        $response = Http::withHeaders([
            'Secret' => config('sikd.webhook_secret'),
        ])->attach('document_esign_file', $fileUpload, $fileName)
        ->attach('document_esign_attachment', $fileUpload, $fileName)
        ->post(config('sikd.webhook_url'), $documentRequest);


    }
}
