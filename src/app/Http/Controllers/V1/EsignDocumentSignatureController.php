<?php

namespace App\Http\Controllers\V1;

use App\Enums\MediumTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\EsignDocumentSignatureRequest;
use App\Http\Traits\SignInitDocumentSignatureTrait;

class EsignDocumentSignatureController extends Controller
{
    use SignInitDocumentSignatureTrait;

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function __invoke(EsignDocumentSignatureRequest $request)
    {
        if ($request->esign_type == SignatureMethodTypeEnum::SINGLEFILE()) {
            return $this->doSingleFileEsignMethod($request);
        }

        if ($request->esign_type == SignatureMethodTypeEnum::MULTIFILE()) {
            return $this->doMultiFileEsignMethod($request);
        }
    }

    protected function doSingleFileEsignMethod($request)
    {
        $requestInput = [
            'id' => ($request->is_signed_self == true) ? $request->document_signature_ids[0] : $request->document_signature_sent_ids[0],
            'passphrase' => $request->passphrase,
            'isSignedSelf' => $request->is_sign_self,
            'medium' => MediumTypeEnum::WEBSITE()
        ];

        try {
            return $this->setupSingleFileEsignDocumentSignature($requestInput, $request->people_id);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }

    protected function doMultiFileEsignMethod($request)
    {
        $requestInput = [
            'id' => ($request->is_signed_self == true) ? $request->document_signature_ids : $request->document_signature_sent_ids,
            'passphrase' => $request->passphrase,
            'isSignedSelf' => $request->is_sign_self,
            'fcmToken' => $request->fcm_token ?? null,
            'medium' => MediumTypeEnum::WEBSITE()
        ];

        $checkMaximumMultipleEsign = $this->checkMaximumMultipleEsign($requestInput['documents']);
        if ($checkMaximumMultipleEsign != true) {
            return $checkMaximumMultipleEsign;
        }

        try {
            return $this->setupMultiFileEsignDocumentSignature($requestInput, $request->people_id, $request->is_signed_self);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }

}
