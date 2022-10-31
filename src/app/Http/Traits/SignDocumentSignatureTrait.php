<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\DocumentSignatureSentNotificationTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Models\DocumentSignatureSent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Setup configuration for signature document
 */
trait SignDocumentSignatureTrait
{
    use SignActionDocumentSignatureTrait;
    use KafkaTrait;
    use KafkaSignDocumentSignatureTrait;
    use SendNotificationTrait;
    use SignatureTrait;

    /**
     * processSignDocumentSignature
     *
     * @param  mixed $documentSignatureSentId
     * @param  mixed $passphrase
     * @param  array $documentSignatureEsignData
     * @param  array $header
     * @return void
     */
    protected function processSignDocumentSignature($documentSignatureSentId, $passphrase, $documentSignatureEsignData)
    {
        $documentSignatureSent = DocumentSignatureSent::findOrFail($documentSignatureSentId);
        if ($documentSignatureEsignData['esignMethod'] == SignatureMethodTypeEnum::MULTIFILE()) {
            $documentSignatureSent->progress_queue = SignatureQueueTypeEnum::PROCESS();
            $documentSignatureSent->save();
        }

        $setupConfig = $this->setupConfigSignature($documentSignatureEsignData['userId']); // add user id for queue
        $file = $this->fileExist($documentSignatureSent->documentSignature->url);
        if (!$file) {
            $logData = $this->setKafkaDocumentNotFoundSignatureSent();
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $documentSignatureSentId, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }
        $checkUserResponse = json_decode($this->checkUserSignature($setupConfig, $documentSignatureSent, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData['esignMethod']));
        if ($checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_OK()->value) {
            $logData = $this->setKafkaBsreAvailable($checkUserResponse);
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            $signature = $this->doSignature($setupConfig, $documentSignatureSent, $passphrase, $documentSignatureEsignData);

            return $signature;
        } else {
            return $this->invalidResponseCheckUserSignature($checkUserResponse, $documentSignatureSent, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData);
        }
    }

    /**
     * doSignature
     *
     * @param  array $setupConfig
     * @param  collection $data
     * @param  string $passphrase
     * @param  array $documentSignatureEsignData
     * @return collection
     */
    protected function doSignature($setupConfig, $data, $passphrase, $documentSignatureEsignData)
    {
        $setNewFileData = $this->setNewFileData($data);
        $pdfFile = $this->pdfFile($data, $setNewFileData['verifyCode'], $documentSignatureEsignData);
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $setupConfig['auth'], 'Cookie' => 'JSESSIONID=' . $setupConfig['cookies'],
        ])->attach('file', $pdfFile, $data->documentSignature->file)->post($setupConfig['url'] . '/api/sign/pdf', [
            'nik'           => $setupConfig['nik'],
            'passphrase'    => $passphrase,
            'tampilan'      => 'invisible',
            'image'         => 'false',
        ]);

        if ($response->status() != Response::HTTP_OK) {
            $bodyResponse = json_decode($response->body());
            $this->setPassphraseSessionLog($response, $data, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData);
            $logData = [
                'message' => 'Gagal melakukan tanda tangan elektronik',
                'longMessage' => $bodyResponse->error
            ];
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        } else {
            //Save new file & update status
            $this->saveNewFile($response, $data, $setNewFileData, $documentSignatureEsignData);
            $this->setPassphraseSessionLog($response, $data, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData);
            return $data;
        }
    }

    /**
     * setNewFileData
     *
     * @param  collection $data
     * @return array
     */
    protected function setNewFileData($data)
    {
        $newFileData = [
            'newFileName' => $data->documentSignature->document_file_name,
            'verifyCode'  => strtoupper(substr(sha1(uniqid(mt_rand(), true)), 0, 10))
        ];

        return $newFileData;
    }

    /**
     * pdfFile
     *
     * @param  mixed $data
     * @param  string $verifyCode
     * @param  array $documentSignatureEsignData
     * @return mixed
     */
    protected function pdfFile($data, $verifyCode, $documentSignatureEsignData)
    {
        if ($data->documentSignature->has_footer == false) {
            $pdfFile = $this->addFooterDocument($data, $verifyCode, $documentSignatureEsignData);
        } else {
            $pdfFile = file_get_contents($data->documentSignature->url);
        }

        return $pdfFile;
    }

    /**
     * fileExist
     *
     * @param  mixed $url
     * @return void
     */
    public function fileExist($url)
    {
        $headers = get_headers($url);
        return stripos($headers[0], "200 OK") ? true : false;
    }

    /**
     * addFooterDocument
     *
     * @param  mixed  $data
     * @param  string $verifyCode
     * @param  array $documentSignatureEsignData
     * @return mixed
     */
    protected function addFooterDocument($data, $verifyCode, $documentSignatureEsignData)
    {
        try {
            $addFooter = Http::attach(
                'pdf',
                file_get_contents($data->documentSignature->url),
                $data->documentSignature->file
            )->post(config('sikd.add_footer_url'), [
                'qrcode' => config('sikd.url') . 'verification/document/tte/' . $verifyCode . '?source=qrcode',
                'category' => $data->documentSignature->documentSignatureType->document_paper_type,
                'code' => $verifyCode
            ]);

            return $addFooter;
        } catch (\Throwable $th) {
            $logData = [
                'event' => 'esign_FOOTER_pdf',
                'status' => KafkaStatusTypeEnum::ESIGN_FOOTER_FAILED_UNKNOWN(),
                'esign_source_file' => $data->documentSignature->url,
                'esign_response' => $th,
                'message' => 'Gagal menambahkan QRCode dan text footer',
                'longMessage' => 'Gagal menambahkan QRCode dan text footer kedalam PDF, silahkan coba kembali'
            ];

            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }
    }

    /**
     * saveNewFile
     *
     * @param  object $response
     * @param  collection $data
     * @param  array $setNewFileData
     * @param  array $documentSignatureEsignData
     * @return collection
     */
    protected function saveNewFile($pdf, $data, $setNewFileData, $documentSignatureEsignData)
    {
        //save to storage path for temporary file
        Storage::disk('local')->put($setNewFileData['newFileName'], $pdf->body());

        //find this document is not last on list
        $nextDocumentSent = $this->findNextDocumentSent($data);

        //transfer to existing service
        $response = $this->doTransferFile($data, $setNewFileData['newFileName'], $nextDocumentSent, $documentSignatureEsignData['esignMethod']);
        Storage::disk('local')->delete($setNewFileData['newFileName']);

        if ($response->status() != Response::HTTP_OK) {
            $logData = $this->logInvalidTransferFile('esign_transfer_pdf', $data->documentSignature->url, $response);
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        } else {
            return $this->updateDocumentSentStatus($data, $setNewFileData, $nextDocumentSent, $documentSignatureEsignData);
        }
    }

    /**
     * doTransferFile
     *
     * @param collection $data
     * @param string $newFileName
     * @param collection $nextDocumentSent
     * @param enum $esignMethod
     * @return mixed
     */
    protected function doTransferFile($data, $newFileName, $nextDocumentSent, $esignMethod)
    {
        try {
            $documentRequest = [
                'first_tier' => false,
                'last_tier' => false,
                'document_name' => $data->documentSignature->file // original name file before renamed
            ];

            if ($data->urutan == 1) {
                // Remove original file (first tier)
                $documentRequest['first_tier'] = true;
            }

            if (!$nextDocumentSent && $data->documentSignature->documentSignatureType->is_mandatory_registered == false) {
                // Remove draft file (last tier) if document didn't required to register before download/distribute
                $documentRequest['last_tier'] = true;
                $documentRequest['document_name'] = $data->documentSignature->tmp_draft_file;
            }

            $fileSignatured = fopen(Storage::path($newFileName), 'r');
            $response = Http::withHeaders([
                'Secret' => config('sikd.webhook_secret'),
            ])->attach('signature', $fileSignatured, $newFileName)->post(config('sikd.webhook_url'), $documentRequest);

            return $response;
        } catch (\Throwable $th) {
            $logData = $this->logInvalidConnectTransferFile('esign_transfer_pdf', $data->documentSignature->url, $th);
            $this->kafkaPublish('analytic_event', $logData);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $esignMethod, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }
    }

    /**
     * updateDocumentSentStatus
     *
     * @param  collection $data
     * @param  array $setNewFileData
     * @param  collection $nextDocumentSent
     * @param  array $documentSignatureEsignData
     * @return collection
     */
    protected function updateDocumentSentStatus($data, $setNewFileData, $nextDocumentSent, $documentSignatureEsignData)
    {
        DB::beginTransaction();
        try {
            //update document after esign (set if new file or update last activity)
            $this->updateDocumentSignatureAfterEsign($data, $setNewFileData);

            //update status document sent to 1 (signed)
            $this->updateDocumentSignatureSentStatusAfterEsign($data, $documentSignatureEsignData['esignMethod']);

            //Send notification status to who esign the document if multi-file esign
            if ($documentSignatureEsignData['esignMethod'] == SignatureMethodTypeEnum::MULTIFILE()) {
                $this->doSendNotificationSelf($data->id, $documentSignatureEsignData);
            }

            //check if any next siganture require
            if ($nextDocumentSent) {
                $this->updateNextDocumentSent($nextDocumentSent->id);
                //Send notification to next people
                $this->doSendNotification($nextDocumentSent->id, $documentSignatureEsignData['esignMethod']);
            } else { // if this is last people
                $this->updateDocumentSignatureLastPeopleAction($data->ttd_id);
                // update passed people at document signature list when last people already signed
                $this->updateDocumentSignatureSentMissedAction($data->ttd_id);
                //Send notification to sender
                $this->doSendForwardNotification($data->id, $data->receiver->PeopleName, $documentSignatureEsignData['esignMethod']);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            $logData = $this->setLogFailedUpdateDataAfterEsign($data, $th);
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);

            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }

        return $data;
    }

    /**
     * setLogFailedUpdateDataAfterEsign
     *
     * @param  collection $data
     * @param  mixed $th
     * @return array
     */
    protected function setLogFailedUpdateDataAfterEsign($data, $th)
    {
        return [
            'event' => 'esign_update_status_document_upload_pdf',
            'status' => KafkaStatusTypeEnum::ESIGN_INVALID_UPDATE_STATUS_AND_DATA(),
            'esign_source_file' => $data->documentSignature->url,
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
            'body' => 'Anda telah berhasil di tandatangani oleh Anda',
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
