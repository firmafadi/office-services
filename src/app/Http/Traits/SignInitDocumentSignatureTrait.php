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

    public function setupSingleFileEsignDocument($requestInput, $userId = null)
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

        return $this->doSingleFileEsignDocument($requestInput, $userId);
    }

    protected function doSingleFileEsignDocument($requestInput, $userId)
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

    public function setupMultiFileEsignDocument($requestInput, $userId = null)
    {
        // set rule for queue only failed or null and non signed/rejected data
        if ($requestInput['isSignedSelf'] == true) {
            $documents = $this->listDocumentSignatureMultiple($requestInput['id']);
        } else {
            $documents = $this->listDocumentSignatureSentMultiple($requestInput['id']);
        }
        if ($documents->isEmpty()) {
            throw new CustomException(
                'Antrian dokumen telah dieksekusi',
                'Antrian dokumen telah dieksekusi oleh sistem, silahkan menunggu hingga selesai.'
            );
        }

        return $this->doMultiFileEsignDocument($documents, $requestInput, $userId);
    }

    public function doMultiFileEsignDocument($documents, $requestInput, $userId = null)
    {
        $setupConfig = $this->setupCheckUserSignature($userId);
        if ($setupConfig == true) {
            $requestInput['userId']      = ($userId != null) ? $userId : auth()->user()->PeopleId;
            $requestInput['header']      = getallheaders();
            $this->doDocumentSignatureMultiple($documents, $requestInput);
        } else {
            return $setupConfig;
        }

        return $documents;
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
     * @param  array $items
     * @param  array $requestInput
     * @return array
     */
    protected function doDocumentSignatureMultiple($items, $requestInput)
    {
        foreach ($items as $item) {
            ProcessMultipleEsignDocument::dispatch($item->id, $items, $requestInput);
        }

        return $items;
    }
}
