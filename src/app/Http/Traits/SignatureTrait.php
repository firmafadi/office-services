<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\PassphraseSession;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Setup configuration for signature document
 */
trait SignatureTrait
{
    use KafkaTrait;

    /**
     * setupConfigSignature
     *
     * @return array
     */
    public function setupConfigSignature()
    {
        $setup = [
            'nik' => (config('sikd.enable_sign_with_nik')) ? auth()->user()->NIK : config('sikd.signature_nik'),
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
            throw new CustomException('Gagal terhubung untuk pengecekan NIK ke API BSrE', $th->getMessage());
        }
    }

    /**
     * invalidResponseCheckUserSignature
     *
     * @param  mixed $checkUserResponse
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
        throw new CustomException($logData['message'], $logData['longMessage']);
    }

    /**
     * createPassphraseSessionLog
     *
     * @param  mixed $response
     * @return void
     */
    public function createPassphraseSessionLog($response, $type, $data)
    {
        try {
            $identifyDocument = $this->doIdentifyDocument($type, $data);

            $passphraseSession = new PassphraseSession();
            $passphraseSession->nama_lengkap    = auth()->user()->PeopleName;
            $passphraseSession->jam_akses       = Carbon::now();
            $passphraseSession->keterangan      = 'Berhasil melakukan TTE dari mobile';
            $passphraseSession->log_desc        = 'OK';

            $logData = $this->setDefaultLogData($response, $identifyDocument, $type);

            if ($response->status() != Response::HTTP_OK) {
                $bodyResponse = json_decode($response->body());
                $passphraseSession->keterangan  = 'Gagal melakukan TTE dari mobile';
                $passphraseSession->log_desc    = $bodyResponse->error . ' | File : ' . $identifyDocument['id'] . ' | User : ' . auth()->user()->PeopleId . ' Type : ' . $type;

                $logData['event']   = 'esign_sign_pdf';
                $logData['status']  = KafkaStatusTypeEnum::ESIGN_FAILED();
                $logData['message'] = $bodyResponse->error;
            }

            $this->kafkaPublish('analytic_event', $logData);

            $passphraseSession->save();

            return $passphraseSession;
        } catch (\Throwable $th) {
            throw new CustomException('Gagal menyimpan log TTE', $th->getMessage());
        }
    }

    /**
     * doIdentifyDocument
     *
     * @param  enum $type
     * @param  mixed $data
     * @return array
     */
    public function doIdentifyDocument($type, $data)
    {
        if ($type == SignatureDocumentTypeEnum::DRAFTING_DOCUMENT()) {
            return [
                'id' => $data->NId_temp,
                'file' => $data->draft_file
            ];
        }

        if ($type == SignatureDocumentTypeEnum::UPLOAD_DOCUMENT()) {
            return [
                'id' => $data->documentSignature->id,
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
}
