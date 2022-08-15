<?php

namespace App\GraphQL\Mutations;

use App\Enums\DocumentSignatureSentNotificationTypeEnum;
use App\Enums\FcmNotificationActionTypeEnum;
use App\Enums\FcmNotificationListTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Enums\StatusReadTypeEnum;
use App\Http\Traits\SendNotificationTrait;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;
use Illuminate\Support\Arr;

class DocumentSignatureRejectMutator
{
    use SendNotificationTrait;
    use KafkaTrait;

    /**
     * @param $rootValue
     * @param $args
     *
     * @throws \Exception
     *
     * @return array
     */
    public function reject($rootValue, array $args)
    {
        $documentSignatureSentId = Arr::get($args, 'input.documentSignatureSentId');
        $note                    = Arr::get($args, 'input.note');

        $documentSignatureSent = DocumentSignatureSent::where('id', $documentSignatureSentId)->first();

        if (!$documentSignatureSent) {
            throw new CustomException(
                'Document not found',
                'Docuement with this id not found'
            );
        }

        $documentSignatureSent->status              = SignatureStatusTypeEnum::REJECT()->value;
        $documentSignatureSent->catatan             = $note;
        $documentSignatureSent->tgl_ttd             = setDateTimeNowValue();
        $documentSignatureSent->is_sender_read      = false;
        $documentSignatureSent->forward_receiver_id = $documentSignatureSent->PeopleID;
        $documentSignatureSent->save();

        DocumentSignature::where('id', $documentSignatureSent->ttd_id)->update([
            'status' => SignatureStatusTypeEnum::REJECT()->value,
        ]);

        $this->doSendNotification($documentSignatureSentId);

        $this->kafkaPublish('analytic_event', [
            'event' => 'reject_esign',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'letter' => [
                'id' => $documentSignatureSentId
            ]
        ]);

        return $documentSignatureSent;
    }

    /**
     * doSendNotification
     *
     * @param  object $data
     * @return void
     */
    protected function doSendNotification($documentSignatureSentId)
    {
        $messageAttribute = [
            'notification' => [
                'title' => 'Penolakan TTE Naskah',
                'body' => 'Ada naskah yang tidak berhasil ditandatangani. Silakan klik disini untuk mengecek alasannya.',
            ],
            'data' => [
                'documentSignatureSentId' => $documentSignatureSentId,
                'target' => DocumentSignatureSentNotificationTypeEnum::SENDER(),
                'action' => FcmNotificationActionTypeEnum::DOC_SIGNATURE_DETAIL(),
                'list' => FcmNotificationListTypeEnum::SIGNATURE()
            ]
        ];

        $this->setupDocumentSignatureSentNotification($messageAttribute);
    }
}
