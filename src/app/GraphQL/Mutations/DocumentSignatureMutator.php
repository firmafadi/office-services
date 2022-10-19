<?php

namespace App\GraphQL\Mutations;

use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Http\Traits\KafkaSignDocumentSignatureTrait;
use App\Http\Traits\SignatureTrait;
use App\Http\Traits\SignDocumentSignatureTrait;
use App\Models\DocumentSignatureSent;
use Illuminate\Support\Arr;

class DocumentSignatureMutator
{
    use KafkaSignDocumentSignatureTrait;
    use SignatureTrait;
    use SignDocumentSignatureTrait;

    /**
     * @param $rootValue
     * @param $args
     *
     * @throws \Exception
     *
     * @return array
     */
    public function signature($rootValue, array $args)
    {
        $documentSignatureSentId    = Arr::get($args, 'input.documentSignatureSentId');
        $passphrase                 = Arr::get($args, 'input.passphrase');
        $documentSignatureEsignData = [
            'userId' => auth()->user()->PeopleId,
            'esignMethod' => SignatureMethodTypeEnum::SINGLEFILE()
        ];

        $documentSignatureSent = DocumentSignatureSent::findOrFail($documentSignatureSentId);

        $logData = $this->setKafkaDocumentApproveResponse($documentSignatureSent->id);
        if ($documentSignatureSent->status != SignatureStatusTypeEnum::WAITING()->value) {
            $logData['message'] = 'Dokumen telah ditandatangani';
            $logData['longMessage'] = 'Dokumen ini telah ditandatangani oleh Anda';
            $this->kafkaPublish('analytic_event', $logData);

            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $documentSignatureSentId, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }

        return $this->processSignDocumentSignature($documentSignatureSentId, $passphrase, $documentSignatureEsignData);
    }
}
