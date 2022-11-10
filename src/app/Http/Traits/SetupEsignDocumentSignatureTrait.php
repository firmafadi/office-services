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

    public function setupSingleFileEsignDocumentSignature($requestInput, $userId = null)
    {
        $documentSignatureSent = DocumentSignatureSent::findOrFail($requestInput['documentSignatureSentId']);
        $logData = $this->setKafkaDocumentApproveResponse($documentSignatureSent->id);
        if ($documentSignatureSent->status != SignatureStatusTypeEnum::WAITING()->value) {
            return $this->setResponseDocumentAlreadySigned($logData);
        }

        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig == true) {
            $documentSignatureEsignData = $this->setSingleFileDocumetnSignatureEsignData($userId);
            return $this->initProcessSignDocumentSignature($requestInput['documentSignatureSentId'], $requestInput['passphrase'], $documentSignatureEsignData);
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
        $documentSignatureSents = $this->listDocumentSignatureSentMultiple($requestInput['documents']);
        if ($documentSignatureSents->isEmpty()) {
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
                'header'        => getallheaders(),
                'medium'        => $requestInput['medium']
            ];

            $this->doDocumentSignatureMultiple($documentSignatureSents, $requestUserData);
        } else {
            return $setupConfig;
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
     * listDocumentSignatureSentMultiple
     *
     * @param  array $splitDocumentSignatureSentIds
     * @return collection
     */
    protected function listDocumentSignatureSentMultiple($splitDocumentSignatureSentIds)
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
