<?php

namespace App\Http\Controllers\V1;

use App\Enums\MediumTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\EsignDocumentSignatureRequest;
use App\Http\Traits\SignInitDocumentSignatureTrait;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;

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
            // TODO: FCM NOTIFICATION FOR WEBSITE WILL BE ADD LATER
            return $this->doMultiFileEsignMethod($request);
        }
    }

    protected function doSingleFileEsignMethod($request)
    {
        $requestInput = [
            'id' => ($request->is_signed_self == true) ? $request->document_signature_ids[0] : $request->document_signature_sent_ids[0],
            'passphrase' => $request->passphrase,
            'isSignedSelf' => $request->is_signed_self,
            'medium' => MediumTypeEnum::WEBSITE(),
        ];

        try {
            $response = $this->setupSingleFileEsignDocument($requestInput, $request->people_id);
            return response()->json(['data' => $response], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function doMultiFileEsignMethod($request)
    {
        //check user has on process doing esugn queue on redis
        $checkRedis = $this->checkRedisHasOnProcess($request);
        if ($checkRedis !== true) {
            return $checkRedis;
        }

        $requestInput = [
            'id' => ($request->is_signed_self == true) ? $request->document_signature_ids : $request->document_signature_sent_ids,
            'passphrase' => $request->passphrase,
            'isSignedSelf' => $request->is_signed_self,
            'fcmToken' => $request->fcm_token ?? null,
            'medium' => MediumTypeEnum::WEBSITE(),
        ];

        $checkMaximumMultipleEsign = $this->checkMaximumMultipleEsign($requestInput['id']);
        if ($checkMaximumMultipleEsign !== true) {
            return $checkMaximumMultipleEsign;
        }

        try {
            $response = $this->setupMultiFileEsignDocument($requestInput, $request->people_id);
            return response()->json(['data' => $response], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function checkRedisHasOnProcess($request)
    {
        $key = 'esign:document_upload:multifile:website:' . $request->people_id;
        $hasOnProcessQueue = Redis::get($key);
        if (isset($hasOnProcessQueue)) {
            $data = json_decode($hasOnProcessQueue, true);
            if ($data['status'] == SignatureQueueTypeEnum::PROCESS() && $data['hasError'] == false) {
                return response()->json(['message' => 'User tidak dapat melakukan proses tanda tangan elektronik multifile hingga proses yang sedang berjalan telah selesai'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return true;
    }
}
