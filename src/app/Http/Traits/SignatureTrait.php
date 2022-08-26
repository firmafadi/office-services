<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
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
            'event' => 'bsre_nik_invalid',
            'longMessage' => 'Tidak dapat terhubung dengan BSRE, silahkan coba kembali',
            'serviceResponse' => (array) $checkUserResponse
        ];

        if ($checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_NOT_REGISTERED()->value) {
            $logData['message'] = 'Invalid User NIK';
            $logData['longMessage'] = 'User NIK Anda Belum Terdaftar';
        }

        if ($checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_ESIGN_NOT_ACTIVE()->value) {
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
    public function createPassphraseSessionLog($response, $id = null)
    {
        $passphraseSession = new PassphraseSession();
        $passphraseSession->nama_lengkap    = auth()->user()->PeopleName;
        $passphraseSession->jam_akses       = Carbon::now();
        $passphraseSession->keterangan      = 'Berhasil melakukan TTE dari mobile';
        $passphraseSession->log_desc        = 'OK';

        $logData = [
            'event' => 'esign',
            'status' => KafkaStatusTypeEnum::ESIGN_SUCCESS(),
            'letter' => [
                'id' => $id
            ]
        ];

        if ($response->status() != Response::HTTP_OK) {
            $bodyResponse = json_decode($response->body());
            $passphraseSession->keterangan      = 'Gagal melakukan TTE dari mobile';
            $passphraseSession->log_desc        = $bodyResponse->error . ' | File : ' . $id . ' | User : ' . auth()->user()->PeopleId;

            $logData['status'] = KafkaStatusTypeEnum::ESIGN_FAILED();
            $logData['message'] = $bodyResponse->error;
        }

        $this->kafkaPublish('analytic_event', $logData);

        $passphraseSession->save();

        return $passphraseSession;
    }
}
