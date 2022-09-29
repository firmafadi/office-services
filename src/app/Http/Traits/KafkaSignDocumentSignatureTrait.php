<?php

namespace App\Http\Traits;

use App\Enums\KafkaStatusTypeEnum;

/**
 * Setup configuration for signature document
 */
trait KafkaSignDocumentSignatureTrait
{
    /**
     * setKafkaDocumentApproveResponse
     *
     * @param  integer $documentSignatureSentId
     * @return array
     */
    protected function setKafkaDocumentApproveResponse($documentSignatureSentId)
    {
        $logData = [
            'event' => 'document_approve',
            'status' => KafkaStatusTypeEnum::DOCUMENT_APPROVE_FAILED_ALREADY_SIGNED(),
            'letter' => [
                'id' => $documentSignatureSentId
            ],
        ];

        return $logData;
    }

    /**
     * setKafkaDocumentNotFoundSignatureSent
     *
     * @return array
     */
    protected function setKafkaDocumentNotFoundSignatureSent()
    {
        $logData = [
            'event' => 'document_not_found_signature_sent',
            'status' => KafkaStatusTypeEnum::DOCUMENT_APPROVE_FAILED_NOFILE(),
            'message' => 'Dokumen tidak tersedia',
            'longMessage' => 'Dokumen yang akan ditandatangani tidak tersedia'
        ];

        return $logData;
    }

    protected function setKafkaBsreAvailable($checkUserResponse)
    {
        $logData = [
            'event' => 'bsre_nik_available',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'serviceResponse' => (array) $checkUserResponse
        ];

        return $logData;
    }
}
