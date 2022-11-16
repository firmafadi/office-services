<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\MediumTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Jobs\ProcessMultipleEsignDocument;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;
use Illuminate\Support\Facades\Redis;

/**
 * Setup configuration for signature document
 */
trait SignInitDocumentSignatureTrait
{
    use SignActionDocumentSignatureTrait;

    public function setupSingleFileEsignDocument($requestInput, $userId = null)
    {
        $checkAlreadySigned = $this->checkSingleFileAlreadySigned($requestInput);
        if ($checkAlreadySigned != true) {
            return $checkAlreadySigned;
        }
        return $this->doSingleFileEsignDocument($requestInput, $userId);
    }

    protected function checkSingleFileAlreadySigned($requestInput)
    {
        if ($requestInput['isSignedSelf'] == true) {
            $documentSignature = DocumentSignature::findOrFail($requestInput['id']);
            return $this->checkErrorSingleFileAlreadySignedSelf($documentSignature);
        }

        if ($requestInput['isSignedSelf'] == false) {
            $documentSignatureSent = DocumentSignatureSent::findOrFail($requestInput['id']);
            return $this->checkErrorSingleFileAlreadySigned($documentSignatureSent);
        }
    }

    protected function checkErrorSingleFileAlreadySignedSelf($documentSignature)
    {
        $logData = $this->setKafkaDocumentApproveResponse($documentSignature->id);
        if ($documentSignature->status != SignatureStatusTypeEnum::WAITING()->value && $documentSignature->is_signed_self == false) {
            return $this->setResponseDocumentAlreadySigned($logData);
        }
        return true;
    }

    protected function checkErrorSingleFileAlreadySigned($documentSignatureSent)
    {
        $logData = $this->setKafkaDocumentApproveResponse($documentSignatureSent->id);
        if ($documentSignatureSent->status != SignatureStatusTypeEnum::WAITING()->value) {
            return $this->setResponseDocumentAlreadySigned($logData);
        }
        return true;
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
            $requestInput['userId']    = ($userId != null) ? $userId : auth()->user()->PeopleId;
            $requestInput['header']    = getallheaders();
            $requestInput['items']     = $documents->pluck('id')->toArray();
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
     * @param  collection $items
     * @param  array $requestInput
     * @return array
     */
    protected function doDocumentSignatureMultiple($items, $requestInput)
    {
        if ($requestInput['medium'] == MediumTypeEnum::WEBSITE()) {
            $requestEsignMultifileWebsite = json_encode([
                'userId' => $requestInput['userId'],
                'process' => 'esign',
                'status' => SignatureQueueTypeEnum::PROCESS(),
                'hasError' => false,
                'method' => SignatureMethodTypeEnum::MULTIFILE(),
                'medium' => $requestInput['medium'],
                'isSignedSelf' => $requestInput['isSignedSelf'],
                'items' => $requestInput['items']
            ]);
            $key = 'esign:document_upload:multifile:website:' . $requestInput['userId'];
            Redis::set($key, $requestEsignMultifileWebsite, 'EX', config('sikd.redis_exp_default'));
        }
        foreach ($items as $item) {
            ProcessMultipleEsignDocument::dispatch($item->id, $requestInput);
        }

        return $items;
    }
}
