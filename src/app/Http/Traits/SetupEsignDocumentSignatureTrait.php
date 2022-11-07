<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Jobs\ProcessMultipleEsignDocument;
use App\Models\DocumentSignatureSent;

/**
 * Setup configuration for signature document
 */
trait SetupEsignDocumentSignatureTrait
{
    use SignDocumentSignatureTrait;

    public function setupSingleFileEsignDocumentSignature($documentSignatureSentId, $passphrase, $userId = null)
    {
        $documentSignatureSent = DocumentSignatureSent::findOrFail($documentSignatureSentId);

        $logData = $this->setKafkaDocumentApproveResponse($documentSignatureSent->id);
        if ($documentSignatureSent->status != SignatureStatusTypeEnum::WAITING()->value) {
            $logData['message'] = 'Dokumen telah ditandatangani';
            $logData['longMessage'] = 'Dokumen ini telah ditandatangani oleh Anda';
            $this->kafkaPublish('analytic_event', $logData);

            // Set return failure esign
            throw new CustomException($logData['message'], $logData['longMessage']);
        }

        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig) {
            $documentSignatureEsignData = [
                'userId' => ($userId != null) ? $userId : auth()->user()->PeopleId,
                'esignMethod' => SignatureMethodTypeEnum::SINGLEFILE(),
                'header' => getallheaders(),
            ];
            return $this->processSignDocumentSignature($documentSignatureSentId, $passphrase, $documentSignatureEsignData);
        }
    }

    public function setupMultiFileEsignDocumentSignature($arrayOfDocumentSignatureSents, $passphrase, $fcmToken = null, $userId = null) {

        if (count($arrayOfDocumentSignatureSents) > config('sikd.maximum_multiple_esign')) {
            throw new CustomException(
                'Batas maksimal untuk melakukan multi-file esign adalah ' . config('sikd.maximum_multiple_esign') . ' dokumen',
                'Permintaan Anda melewati batas maksimal untuk melakukan multi-file esign.'
            );
        }

        // set rule for queue only failed or null and non signed/rejected data
        $documentSignatureSents = $this->listDocumentSignatureMultiple($arrayOfDocumentSignatureSents);
        if ($documentSignatureSents->isEmpty()) {
            throw new CustomException(
                'Antrian dokumen telah dieksekusi',
                'Antrian dokumen telah dieksekusi oleh sistem, silahkan menunggu hingga selesai.'
            );
        }

        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig) {
            $requestUserData = [
                'fcmToken'      => $fcmToken,
                'userId'        => ($userId != null) ? $userId : auth()->user()->PeopleId,
                'passphrase'    => $passphrase,
                'header'        => getallheaders()
            ];

            $this->doDocumentSignatureMultiple($documentSignatureSents, $requestUserData);
        }

        return $documentSignatureSents;
    }

    protected function setupCheckUserSignature($userId = null)
    {
        $setupConfig = $this->setupConfigSignature($userId);
        $checkUserResponse = json_decode($this->checkUserSignature($setupConfig));
        if (isset($checkUserResponse->status_code) && $checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_OK()->value) {
            $logData = $this->setKafkaBsreAvailable($checkUserResponse);
            $this->kafkaPublish('analytic_event', $logData,);

            return true;
        } else {
            return $this->invalidResponseCheckUserSignature($checkUserResponse);
        }
    }

    /**
     * listDocumentSignatureMultiple
     *
     * @param  array $splitDocumentSignatureSentIds
     * @return collection
     */
    protected function listDocumentSignatureMultiple($splitDocumentSignatureSentIds)
    {
        $query = DocumentSignatureSent::whereIn('id', $splitDocumentSignatureSentIds)
            ->where('status', SignatureStatusTypeEnum::WAITING()->value)
            ->where(function ($query) {
                $query->whereNull('progress_queue')
                    ->orWhere('progress_queue', SignatureQueueTypeEnum::FAILED());
            })->get();

        return $query;
    }

    /**
     * doDocumentSignatureMultiple
     *
     * @param  array $documentSignatureSents
     * @param  array $requestUserData
     * @return array
     */
    protected function doDocumentSignatureMultiple($documentSignatureSents, $requestUserData)
    {
        foreach ($documentSignatureSents as $documentSignatureSent) {
            ProcessMultipleEsignDocument::dispatch($documentSignatureSent->id, $requestUserData);
        }

        return $documentSignatureSents;
    }
}
