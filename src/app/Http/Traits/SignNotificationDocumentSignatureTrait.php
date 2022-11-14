<?php

namespace App\Http\Traits;

use App\Enums\DocumentSignatureSentNotificationTypeEnum;
use App\Enums\FcmNotificationActionTypeEnum;
use App\Enums\FcmNotificationListTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Models\DocumentSignatureSent;

/**
 * Setup configuration for signature document
 */
trait SignNotificationDocumentSignatureTrait
{
    use KafkaTrait;
    use KafkaSignActionDocumentSignatureTrait;
    use SendNotificationTrait;
    use SignatureTrait;

    /**
     * esignFailedExceptionResponse
     *
     * @param  array $message
     * @param  enum $documentSignatureEsignData // set null for case esign draft not yet handled
     * @param  integer $id // set null for case esign draft not yet handled
     * @param  enum $documentType // set null for case esign draft not yet handled
     * @return void
     */
    public function esignFailedExceptionResponse($message, $documentSignatureEsignData = null, $id = null, $documentType = null)
    {
        if ($documentSignatureEsignData['esignMethod'] == null || $documentSignatureEsignData['esignMethod'] == SignatureMethodTypeEnum::SINGLEFILE()) {
            throw new CustomException($message['message'], $message['longMessage']);
        }

        // TODO : !IMPORTANT => ADD CASE FOR SELF SIGN MULTI FILE NOTIFICATION
        /** Add condition multi-file on drafting signature
        * Since multi-file only provide on document upload
        * this condition will be updated later if esign draft multi-file will be implement
        */
        if (
            $id != null &&
            $documentType == SignatureDocumentTypeEnum::UPLOAD_DOCUMENT() &&
            $documentSignatureEsignData['esignMethod'] == SignatureMethodTypeEnum::MULTIFILE() &&
            $documentSignatureEsignData['isSignedSelf'] == false) {
            // set progress queue to failed
            DocumentSignatureSent::where('id', $id)->update([
                'progress_queue' => SignatureQueueTypeEnum::FAILED()
            ]);

            $sendToNotification = [
                'title' => $message['message'],
                'body' => $message['longMessage'],
                'documentSignatureSentId' => $id,
                'target' => DocumentSignatureSentNotificationTypeEnum::RECEIVER(),
                'status' => SignatureStatusTypeEnum::UNSIGNED()
            ];

            $this->doSendNotificationDocumentSignature($sendToNotification, $documentSignatureEsignData['esignMethod']);
        }
    }

    /**
     * doSendNotificationDocumentSignature
     *
     * @param  array $sendToNotification
     * @param  enum $esignMethod
     * @param  string $fcmToken
     * @return mixed
     */
    public function doSendNotificationDocumentSignature($sendToNotification, $esignMethod, $fcmToken = null)
    {
        $messageAttribute = [
            'notification' => [
                'title' => $sendToNotification['title'],
                'body' => $sendToNotification['body']
            ],
            'data' => [
                'documentSignatureSentId' => $sendToNotification['documentSignatureSentId'],
                'target' => $sendToNotification['target'],
                'action' => FcmNotificationActionTypeEnum::DOC_SIGNATURE_DETAIL(),
                'list' => FcmNotificationListTypeEnum::SIGNATURE(),
                'status' => $sendToNotification['status']
            ]
        ];

        if ($esignMethod == SignatureMethodTypeEnum::MULTIFILE()) {
            $messageAttribute['data']['visible'] = false;
        }

        $this->setupDocumentSignatureSentNotification($messageAttribute, $fcmToken);
    }

    /**
     * setLogFailedUpdateDataAfterEsign
     *
     * @param  collection $documentData
     * @param  mixed $th
     * @return array
     */
    protected function setLogFailedUpdateDataAfterEsign($documentData, $th)
    {
        return [
            'event' => 'esign_update_status_document_upload_pdf',
            'status' => KafkaStatusTypeEnum::ESIGN_INVALID_UPDATE_STATUS_AND_DATA(),
            'esign_source_file' => $documentData->url,
            'response' => $th,
            'message' => 'Gagal menyimpan perubahan data',
            'longMessage' => $th->getMessage()
        ];
    }

    /**
     * doSendNotification
     *
     * @param  object $data
     * @param  enum $esignMethod
     * @return void
     */
    protected function doSendNotification($nextDocumentSentId, $esignMethod)
    {
        $sendToNotification = [
            'title' => 'TTE Naskah',
            'body' => 'Terdapat naskah masuk untuk segera Anda tanda tangani secara digital. Klik disini untuk membaca dan menindaklanjuti pesan.',
            'documentSignatureSentId' => $nextDocumentSentId,
            'target' => DocumentSignatureSentNotificationTypeEnum::RECEIVER(),
            'status' => SignatureStatusTypeEnum::SIGNED()
        ];

        $this->doSendNotificationDocumentSignature($sendToNotification, $esignMethod);
    }

    /**
     * doSendNotification
     *
     * @param  integer $id
     * @param  array $documentSignatureEsignData
     * @return void
     */
    protected function doSendNotificationSelf($id, $documentSignatureEsignData)
    {
        $sendToNotification = [
            'title' => 'TTE Berhasil',
            'body' => 'Dokumen Anda telah berhasil di tandatangani',
            'documentSignatureSentId' => $id,
            'target' => DocumentSignatureSentNotificationTypeEnum::RECEIVER(),
            'status' => SignatureStatusTypeEnum::SIGNED()
        ];

        $this->doSendNotificationDocumentSignature($sendToNotification, $documentSignatureEsignData['esignMethod'], $documentSignatureEsignData['fcmToken']);
    }

    /**
     * doSendForwardNotification
     *
     * @param  string $id
     * @param  string $name
     * @param  enum $esignMethod
     * @return void
     */
    protected function doSendForwardNotification($id, $name, $esignMethod)
    {
        $sendToNotification = [
            'title' => 'TTE Naskah',
            'body' => 'Naskah Anda telah di tandatangani oleh ' . $name . '. Klik disini untuk lihat naskah!',
            'documentSignatureSentId' => $id,
            'target' => DocumentSignatureSentNotificationTypeEnum::SENDER(),
            'status' => SignatureStatusTypeEnum::SIGNED()
        ];

        $this->doSendNotificationDocumentSignature($sendToNotification, $esignMethod);
    }
}
