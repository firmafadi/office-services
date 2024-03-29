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
trait SignUpdateDataDocumentSignatureTrait
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
     * updateDocumentSignatureAfterEsign
     *
     * @param  collection $data
     * @param  array $setNewFileData
     * @param  array $documentSignatureEsignData
     * @return void
     */
    protected function updateDocumentSignatureAfterEsign($data, $setNewFileData, $documentSignatureEsignData)
    {
        $id = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data->id : $data->ttd_id;
        $dataDocument = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data : $data->documentSignature;

        if ($documentSignatureEsignData['isSignedSelf'] == true) {
            $updateValue['is_signed_self'] = true;
            $updateValue['tanggal_update'] = setDateTimeNowValue();

            if (!$data->is_forwardable) {
                $this->updateDocumentSignatureLastPeopleAction($id);
            }
        }

        if ($documentSignatureEsignData['esignMethod'] == SignatureMethodTypeEnum::MULTIFILE()) {
            $updateValue['progress_queue']  = SignatureQueueTypeEnum::DONE();
        }

        //change filename with _signed & update stastus
        $updateValue['last_activity'] = setDateTimeNowValue();
        if (!$dataDocument->has_footer) {
            $updateValue['file']        = $setNewFileData['newFileName'];
            $updateValue['code']        = $setNewFileData['verifyCode'];
            $updateValue['has_footer']  = true;
        }
        $updateFileData = DocumentSignature::where('id', $id)->update($updateValue);

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
        }
        $data->save();

        return $data;
    }
}
