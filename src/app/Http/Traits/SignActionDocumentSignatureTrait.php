<?php

namespace App\Http\Traits;

use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Enums\SignatureVisibleTypeEnum;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;

/**
 * Setup configuration for signature document
 */
trait SignActionDocumentSignatureTrait
{
    /**
     * findNextDocumentSent
     *
     * @param  collection $data
     * @return collection
     */
    protected function findNextDocumentSent($data)
    {
        $nextDocumentSent = DocumentSignatureSent::where('ttd_id', $data->ttd_id)
                                                    ->where('urutan', $data->urutan + 1)
                                                    ->first();
        return $nextDocumentSent;
    }

    /**
     * updateNextDocument
     *
     * @param  integer $nextDocumentSentId
     * @return void
     */
    protected function updateNextDocumentSent($nextDocumentSentId)
    {
        $updateNextDocument = DocumentSignatureSent::where('id', $nextDocumentSentId)->update([
            'next' => SignatureVisibleTypeEnum::SHOW()->value
        ]);

        return $updateNextDocument;
    }

    /**
     * updateDocumentSignatureNewFileAction
     *
     * @param  integer $documentId
     * @param  array $setNewFileData
     * @return void
     */
    protected function updateDocumentSignatureNewFileAction($documentId, $setNewFileData)
    {
        $updateFileData = DocumentSignature::where('id', $documentId)->update([
            'file' => $setNewFileData['newFileName'],
            'code' => $setNewFileData['verifyCode'],
            'has_footer' => true,
        ]);

        return $updateFileData;
    }

    /**
     * updateDocumentSignatureLastPeopleAction
     *
     * @param  integer $documentId
     * @return void
     */
    protected function updateDocumentSignatureLastPeopleAction($documentId)
    {
        $updateFileData = DocumentSignature::where('id', $documentId)->update([
            'status' => SignatureStatusTypeEnum::SUCCESS()->value,
        ]);

        return $updateFileData;
    }

    /**
     * updateDocumentSignatureSentMissedAction
     *
     * @param  integer $documentId
     * @return void
     */
    protected function updateDocumentSignatureSentMissedAction($documentId)
    {
        $updateMissedList = DocumentSignatureSent::where('ttd_id', $documentId)
                                                ->where('status', SignatureStatusTypeEnum::WAITING()->value)
                                                ->update([
                                                    'status' => SignatureStatusTypeEnum::MISSED()->value
                                                ]);

        return $updateMissedList;
    }

    /**
     * updateDocumentSignatureSentStatusAfterEsign
     *
     * Update method from collection for dynamic attribute, for example multi-file condition
     *
     * @param  collection $data
     * @param  enum $esignMethod
     * @return void
     */
    protected function updateDocumentSignatureSentStatusAfterEsign($data, $esignMethod)
    {
        $data->status           = SignatureStatusTypeEnum::SUCCESS()->value;
        $data->next             = SignatureVisibleTypeEnum::SHOW()->value;
        $data->tgl_ttd          = setDateTimeNowValue();
        $data->is_sender_read   = false;
        $data->is_receiver_read = true;
        if ($esignMethod == SignatureMethodTypeEnum::MULTIFILE()) {
            $data->progress_queue = SignatureQueueTypeEnum::DONE();
            //send notification success to user do esign
            $this->doSendNotificationSelf($data->id, $esignMethod);
        }
        $data->save();

        return $data;
    }
}
