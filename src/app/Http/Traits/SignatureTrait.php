<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\DocumentSignatureSentNotificationTypeEnum;
use App\Enums\FcmNotificationActionTypeEnum;
use App\Enums\FcmNotificationListTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignatureSent;
use App\Models\PassphraseSession;
use App\Models\People;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Setup configuration for signature document
 */
trait SignatureTrait
{
    use KafkaTrait;
    use SendNotificationTrait;

    /**
     * setupConfigSignature
     *
     * @param  integer $userId
     * @return array
     */
    public function setupConfigSignature($userId = null)
    {
        $userData = $this->setUserData($userId);
        $setup = [
            'nik' => (config('sikd.enable_sign_with_nik')) ? $userData->NIK : config('sikd.signature_nik'),
            'url' => config('sikd.signature_url'),
            'auth' => config('sikd.signature_auth'),
            'cookies' => config('sikd.signature_cookies'),
        ];

        return $setup;
    }

    /**
     * checkUserSignature
     *
     * @param  array $setupConfig
     * @param  mixed $data
     * @param  enum $documentType
     * @param  enum $esignMethod
     * @return string
     */
    public function checkUserSignature($setupConfig, $data, $documentType, $esignMethod = null)
    {
        $identifyDocument = $this->doIdentifyDocument($documentType, $data);
        $checkUrl = $setupConfig['url'] . '/api/user/status/' . $setupConfig['nik'];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $setupConfig['auth'],
                'Cookie' => 'JSESSIONID=' . $setupConfig['cookies'],
            ])->get($checkUrl);

            return $response->body();
        } catch (\Throwable $th) {
            $logData = [
                'message' => 'Gagal terhubung untuk pengecekan NIK ke API BSrE',
                'longMessage' => $th->getMessage(),
            ];

            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $esignMethod, $identifyDocument['id'], $documentType);
        }
    }

    /**
     * invalidResponseCheckUserSignature
     *
     * @param  mixed $checkUserResponse
     * @param  mixed $documentSignatureSentId
     * @param  enum $esignMethod
     * @return void
     */
    public function invalidResponseCheckUserSignature($checkUserResponse, $data, $documentType, $esignMethod = null)
    {
        $identifyDocument = $this->doIdentifyDocument($documentType, $data);

        $logData = [
            'message' => 'Invalid BSRE Service',
            'event' => 'esign_check_user_status',
            'status' => 'esign_check_user_status_invalid_bsre',
            'longMessage' => 'Tidak dapat terhubung dengan BSRE, silahkan coba kembali',
            'serviceResponse' => (array) $checkUserResponse
        ];

        if ($checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_NOT_REGISTERED()->value) {
            $logData['status'] = 'esign_check_user_status_not_registered';
            $logData['message'] = 'Invalid User NIK';
            $logData['longMessage'] = 'User NIK Anda Belum Terdaftar';
        }

        if ($checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_ESIGN_NOT_ACTIVE()->value) {
            $logData['status'] = 'esign_check_user_status_not_have_certified';
            $logData['message'] = 'Sertifikat Tidak Aktif';
            $logData['longMessage'] = 'User NIK sudah terdaftar tapi belum memiliki sertifikat esign yang aktif';
        }

        $this->kafkaPublish('analytic_event', $logData);

        // Set return failure esign
        $this->esignFailedExceptionResponse($logData, $esignMethod, $identifyDocument['id'], $documentType);
    }

    /**
     * setPassphraseSessionLog
     *
     * @param  mixed $response
     * @param  mixed $data
     * @param  enum $documentType
     * @param  array $documentSignatureEsignData
     * @return void
     */
    public function setPassphraseSessionLog($response, $data, $documentType, $documentSignatureEsignData = null)
    {
        try {
            $userId = ($documentSignatureEsignData != null) ? $documentSignatureEsignData['userId'] : null; // send null value if documentSignatureEsignData equal null
            $userData = $this->setUserData($userId);
            $identifyDocument = $this->doIdentifyDocument($documentType, $data);

            $passphraseSession = $this->savePassphraseSessionLog($response, $userData, $identifyDocument, $documentType);

            return $passphraseSession;
        } catch (\Throwable $th) {
            $logData = [
                'message' => 'Gagal menyimpan log TTE',
                'longMessage' => $th->getMessage()
            ];
            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $identifyDocument['id'], $documentType);
        }
    }

    /**
     * savePassphraseSessionLog
     *
     * @param  mixed $response
     * @param  mixed $userData
     * @param  mixed $identifyDocument
     * @param  enum $documentType
     * @return void
     */
    public function savePassphraseSessionLog($response, $userData, $identifyDocument, $documentType)
    {
        $passphraseSession = new PassphraseSession();
        $passphraseSession->nama_lengkap    = $userData->PeopleName;
        $passphraseSession->jam_akses       = Carbon::now();
        $passphraseSession->keterangan      = 'Berhasil melakukan TTE dari mobile';
        $passphraseSession->log_desc        = 'OK';

        $logData = $this->setDefaultLogData($response, $identifyDocument, $documentType);

        if ($response->status() != Response::HTTP_OK) {
            $bodyResponse = json_decode($response->body());
            $passphraseSession->keterangan  = 'Gagal melakukan TTE dari mobile';
            $passphraseSession->log_desc    = $bodyResponse->error . ' | File : ' . $identifyDocument['documentId'] . ' | User : ' . $userData->PeopleId . ' Type : ' . $documentType;

            $logData['event']   = 'esign_sign_pdf';
            $logData['status']  = KafkaStatusTypeEnum::ESIGN_FAILED();
            $logData['message'] = $bodyResponse->error;
        }

        $passphraseSession->save();
        $this->kafkaPublish('analytic_event', $logData);

        return $passphraseSession;
    }

    /**
     * doIdentifyDocument
     *
     * @param  enum $documentType
     * @param  mixed $data
     * @return array
     */
    public function doIdentifyDocument($documentType, $data)
    {
        if ($documentType == SignatureDocumentTypeEnum::DRAFTING_DOCUMENT()) {
            return [
                'id' => $data->NId_temp,
                'documentId' => $data->NId_temp,
                'file' => $data->draft_file
            ];
        }

        if ($documentType == SignatureDocumentTypeEnum::UPLOAD_DOCUMENT()) {
            return [
                'id' => $data->id,
                'documentId' => $data->documentSignature->id,
                'file' => $data->documentSignature->url
            ];
        }
    }

    /**
     * setDefaultLogData
     *
     * @param  mixed $response
     * @param  array $identifyDocument
     * @param  enum $type
     * @return array
     */
    public function setDefaultLogData($response, $identifyDocument, $type)
    {
        return [
            'event' => 'esign_sign_pdf',
            'status' => KafkaStatusTypeEnum::ESIGN_SUCCESS(),
            'letter' => [
                'id' => $identifyDocument['id'],
                'type' => $type
            ],
            'esign_source_file' => $identifyDocument['file'],
            'esign_response_http_code' => $response->status(),
            'esign_response' => $response,
        ];
    }

    /**
     * setUserData
     *
     * @param  integer $userId
     * @return object
     */
    public function setUserData($userId = null)
    {
        if ($userId != null) {
            return People::where('PeopleId', $userId)->first();
        }

        return auth()->user();
    }

    /**
     * esignFailedExceptionResponse
     *
     * @param  array $message
     * @param  enum $esignMethod // set null for case esign draft not yet handled
     * @param  integer $id // set null for case esign draft not yet handled
     * @param  enum $documentType // set null for case esign draft not yet handled
     * @return void
     */
    public function esignFailedExceptionResponse($message, $esignMethod = null, $id = null, $documentType = null)
    {
        if ($esignMethod == null || $esignMethod == SignatureMethodTypeEnum::SINGLEFILE()) {
            throw new CustomException($message['message'], $message['longMessage']);
        }

        // TODO
        /** Add condition multi-file on drafting signature
        * Since multi-file only provide on document upload
        * this condition will be updated later if esign draft multi-file will be implement
        */
        if ($id != null && $documentType == SignatureDocumentTypeEnum::UPLOAD_DOCUMENT() && $esignMethod == SignatureMethodTypeEnum::MULTIFILE()) {
            $sendToNotification = [
                'title' => $message['message'],
                'body' => $message['longMessage'],
                'documentSignatureSentId' => $id,
                'target' => DocumentSignatureSentNotificationTypeEnum::RECEIVER(),
            ];

            // set progress queue to failed
            DocumentSignatureSent::where('id', $id)->update([
                'progress_queue' => SignatureQueueTypeEnum::FAILED()
            ]);

            $this->doSendNotificationDocumentSignature($sendToNotification, $esignMethod);
        }
    }

    /**
     * doSendNotificationDocumentSignature
     *
     * @param  array $sendToNotification
     * @return mixed
     */
    public function doSendNotificationDocumentSignature($sendToNotification, $esignMethod)
    {
        $messageAttribute = [
            'notification' => [
                'title' => $sendToNotification['title'],
                'body' => $sendToNotification['body']
            ],
            'data' => [
                'documentSignatureSentId' => $sendToNotification['documentSignatureSentId'],
                'target' => $sendToNotification['target'],
                'action' => FcmNotificationActionTypeEnum::DOC_SIGNATURE_DETAIL(),
                'list' => FcmNotificationListTypeEnum::SIGNATURE()
            ]
        ];

        if ($esignMethod == SignatureMethodTypeEnum::MULTIFILE()) {
            $messageAttribute['data']['visible'] = false;
        }

        $this->setupDocumentSignatureSentNotification($messageAttribute);
    }
}
