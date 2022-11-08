<?php

namespace App\Http\Controllers\V1;

use App\Enums\SignatureMethodTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\EsignDocumentSignatureRequest;
use App\Http\Traits\SetupEsignDocumentSignatureTrait;
use Illuminate\Http\Request;

class EsignDocumentSignatureController extends Controller
{
    use SetupEsignDocumentSignatureTrait;

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function __invoke(EsignDocumentSignatureRequest $request)
    {
        if ($request->hasHeader('Secret') && $request->header('Secret') == config('sikd.webhook_secret')) {
            if ($request->esign_type == SignatureMethodTypeEnum::SINGLEFILE()) {
                return $this->doSingleFileEsignMethod($request);
            }

            if ($request->esign_type == SignatureMethodTypeEnum::MULTIFILE()) {
                return $this->doMultiFileEsignMethod($request);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
    }

    protected function doSingleFileEsignMethod($request)
    {
        $documentSignatureId    = $request->document_signature_ids[0];
        $passphrase             = $request->passphrase;
        $userId                 = $request->people_id;

        try {
            return $this->setupSingleFileEsignDocumentSignature($documentSignatureId, $passphrase, $userId);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }

    protected function doMultiFileEsignMethod($request)
    {
        $documentSignatureIds   = $request->document_signature_ids;
        $passphrase             = $request->passphrase;
        $userId                 = $request->people_id;
        $fcmToken               = null;

        try {
            return $this->setupMultiFileEsignDocumentSignature($documentSignatureIds, $passphrase, $fcmToken, $userId);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }


}
