<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Jobs\ProcessMultipleEsignDocument;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;

/**
 * Setup configuration for signature document
 */
trait SignInitDocumentSignatureTrait
{
    use SignActionDocumentSignatureTrait;

    public function setupSingleFileEsignDocumentSignature($requestInput, $userId = null)
    {
        if ($requestInput['isSignedSelf'] == true) {
            $documentSignature = DocumentSignature::findOrFail($requestInput['id']);
            $logData = $this->setKafkaDocumentApproveResponse($documentSignature->id);
            if ($documentSignature->status != SignatureStatusTypeEnum::WAITING()->value && $documentSignature->is_signed_self == false) {
                return $this->setResponseDocumentAlreadySigned($logData);
            }
        }

        if ($requestInput['isSignedSelf'] == false) {
            $documentSignatureSent = DocumentSignatureSent::findOrFail($requestInput['id']);
            $logData = $this->setKafkaDocumentApproveResponse($documentSignatureSent->id);
            if ($documentSignatureSent->status != SignatureStatusTypeEnum::WAITING()->value) {
                return $this->setResponseDocumentAlreadySigned($logData);
            }
        }

        return $this->doSingleFileEsignDocumentSignature($requestInput, $userId);
    }

    protected function doSingleFileEsignDocumentSignature($requestInput, $userId)
    {
        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig == true) {
            $requestInput['userId']      = ($userId != null) ? $userId : auth()->user()->PeopleId;
            $requestInput['esignMethod'] = SignatureMethodTypeEnum::SINGLEFILE();
            $requestInput['header']      = getallheaders();
            return $this->initProcessSignDocumentSignature($requestInput);
        } else {
            return $setupConfig;
        }
    }

    public function setupMultiFileEsignDocumentSignature($requestInput, $userId = null, $isSignedSelf = null)
    {
        // set rule for queue only failed or null and non signed/rejected data
        $documentSignatureSents = $this->listDocumentSignatureSentMultiple($requestInput['id']);
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
