<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Jobs\ProcessMultipleEsignDocument;
use App\Models\DocumentSignature;

/**
 * Setup configuration for signature document
 */
trait SetupEsignDocumentSignatureTrait
{
    use SignDocumentSignatureTrait;

    public function setupSelfSingleFileEsignDocumentSignature($requestInput, $userId = null, $isSignedSelf = false)
    {
        $documentSignature = DocumentSignature::findOrFail($requestInput['document'], $isSignedSelf);

        $logData = $this->setKafkaDocumentApproveResponse($documentSignature->id);
        if ($documentSignature->status != SignatureStatusTypeEnum::WAITING()->value && $documentSignature->is_signed_self == false) {
            return $this->setResponseDocumentAlreadySigned($logData);
        }

        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig == true) {
            $documentSignatureEsignData = $this->setSingleFileDocumetnSignatureEsignData($userId, $isSignedSelf);
            return $this->processSelfSignDocumentSignature($requestInput['documentSignatureId'], $requestInput['passphrase'], $documentSignatureEsignData);
        } else {
            return $setupConfig;
        }
    }

    public function setupMultiFileEsignDocumentSignature($requestInput, $userId = null, $isSignedSelf = null)
    {
        if (count($requestInput['documents']) > config('sikd.maximum_multiple_esign')) {
            throw new CustomException(
                'Batas maksimal untuk melakukan multi-file esign adalah ' . config('sikd.maximum_multiple_esign') . ' dokumen',
                'Permintaan Anda melewati batas maksimal untuk melakukan multi-file esign.'
            );
        }

        // set rule for queue only failed or null and non signed/rejected data
        $documentSignatures = $this->listDocumentSignatureMultiple($requestInput['documents']);
        if ($documentSignatures->isEmpty()) {
            throw new CustomException(
                'Antrian dokumen telah dieksekusi',
                'Antrian dokumen telah dieksekusi oleh sistem, silahkan menunggu hingga selesai.'
            );
        }

        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig == true) {
            $requestUserData = [
                'fcmToken'      => $requestInput['fcmToken'],
                'userId'        => ($userId != null) ? $userId : auth()->user()->PeopleId,
                'passphrase'    => $requestInput['passphrase'],
                'header'        => getallheaders()
            ];

            $this->doDocumentSignatureMultiple($documentSignatures, $requestUserData);
        } else {
            return $setupConfig;
        }

        return $documentSignatures;
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
     * @param  array $splitDocumentSignatureIds
     * @return collection
     */
    protected function listDocumentSignatureMultiple($splitDocumentSignatureIds)
    {
        $query = DocumentSignature::whereIn('id', $splitDocumentSignatureIds)
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
     * @param  array $documentSignatures
     * @param  array $requestUserData
     * @return array
     */
    protected function doDocumentSignatureMultiple($documentSignatures, $requestUserData)
    {
        foreach ($documentSignatures as $documentSignature) {
            ProcessMultipleEsignDocument::dispatch($documentSignature->id, $requestUserData);
        }

        return $documentSignatures;
    }
}
