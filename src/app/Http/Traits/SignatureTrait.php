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
use App\Enums\SignatureStatusTypeEnum;
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
     * @return string
     */
    public function checkUserSignature($setupConfig)
    {
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

            throw new CustomException($logData['message'], $logData['longMessage']);
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
    public function invalidResponseCheckUserSignature($checkUserResponse)
    {
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
        throw new CustomException($logData['message'], $logData['longMessage']);
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
        $identifyDocument = $this->doIdentifyDocument($documentType, $data);
        try {
            $userId = ($documentSignatureEsignData != null) ? $documentSignatureEsignData['userId'] : null; // send null value if documentSignatureEsignData equal null
            $userData = [
                'user' => $this->setUserData($userId),
                'header' => ($documentSignatureEsignData != null) ? $documentSignatureEsignData['header'] : null
            ];

            $passphraseSession = $this->savePassphraseSessionLog($response, $userData, $identifyDocument, $documentType);

            return $passphraseSession;
        } catch (\Throwable $th) {
            $logData = [
                'message' => 'Gagal menyimpan log TTE',
                'longMessage' => $th->getMessage()
            ];
            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $identifyDocument['id'], $documentType);
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
        $passphraseSession->nama_lengkap    = $userData['user']->PeopleName;
        $passphraseSession->jam_akses       = Carbon::now();
        $passphraseSession->keterangan      = 'Berhasil melakukan TTE dari mobile';
        $passphraseSession->log_desc        = 'OK';

        $logData = $this->setDefaultLogData($response, $identifyDocument, $documentType);

        if ($response->status() != Response::HTTP_OK) {
            $bodyResponse = json_decode($response->body());
            $passphraseSession->keterangan  = 'Gagal melakukan TTE dari mobile';
            $passphraseSession->log_desc    = $bodyResponse->error . ' | File : ' . $identifyDocument['documentId'] . ' | User : ' . $userData['user']->PeopleId . ' Type : ' . $documentType;

            $logData['event']   = 'esign_sign_pdf';
            $logData['status']  = KafkaStatusTypeEnum::ESIGN_FAILED();
            $logData['message'] = $bodyResponse->error;
        }

        $passphraseSession->save();
        $this->kafkaPublish('analytic_event', $logData, $userData['header']);

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
                'documentId' => $data->id,
                'file' => $data->url
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
        $logData                                = $this->setBasicEsignLogAttribute('esign_sign_pdf', $identifyDocument['file'], $response);
        $logData['status']                      = KafkaStatusTypeEnum::ESIGN_SUCCESS();
        $logData['letter']['id']                = $identifyDocument['id'];
        $logData['letter']['type']              = $type;
        $logData['esign_response_http_code']    = $response->status();

        return $logData;
    }

    /**
     * setUserData
     * GET from DB for jobs function
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
     * setBasicEsignLogAttribute
     *
     * @param  mixed $event
     * @param  mixed $source
     * @param  mixed $response
     * @return void
     */
    protected function setBasicEsignLogAttribute($event, $source, $response)
    {
        return [
            'event'             => $event,
            'esign_source_file' => $source,
            'esign_response'    => $response,
        ];
    }

    /**
     * logDataInvalidTransferFile
     *
     * @param  string $event
     * @param  string $source
     * @param  mixed $response
     * @return array
     */
    protected function logInvalidConnectTransferFile($event, $source, $response)
    {
        $logData                = $this->setBasicEsignLogAttribute($event, $source, $response);
        $logData['status']      = KafkaStatusTypeEnum::ESIGN_TRANSFER_NOT_CONNECT();
        $logData['message']     = 'Gagal terhubung untuk transfer file eSign';
        $logData['longMessage'] = 'Gagal terhubung untuk memindahkan file tertandatangani ke webhook, silahkan coba kembali';

        return $logData;
    }

    /**
     * logDataInvalidTransferFile
     *
     * @param  string $event
     * @param  string $source
     * @param  mixed $response
     * @return array
     */
    protected function logInvalidTransferFile($event, $source, $response)
    {
        $logData                = $this->setBasicEsignLogAttribute($event, $source, $response);
        $logData['status']      = KafkaStatusTypeEnum::ESIGN_TRANSFER_FAILED();
        $logData['message']     = 'Gagal melakukan transfer file eSign';
        $logData['longMessage'] = 'Gagal mengirimkan file tertandatangani ke webhook, silahkan coba kembali';

        return $logData;
    }

    protected function setResponseDocumentAlreadySigned($logData)
    {
        $logData['message'] = 'Dokumen telah ditandatangani';
        $logData['longMessage'] = 'Dokumen ini telah ditandatangani oleh Anda';
        $this->kafkaPublish('analytic_event', $logData);

        // Set return failure esign
        throw new CustomException($logData['message'], $logData['longMessage']);
    }

    public function checkMaximumMultipleEsign($items)
    {
        if (count($items) > config('sikd.maximum_multiple_esign')) {
            throw new CustomException(
                'Batas maksimal untuk melakukan multi-file esign adalah ' . config('sikd.maximum_multiple_esign') . ' dokumen',
                'Permintaan Anda melewati batas maksimal untuk melakukan multi-file esign.'
            );
        }

        return true;
    }
}
