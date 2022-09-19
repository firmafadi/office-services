<?php

namespace App\Http\Traits;

use App\Enums\BsreStatusTypeEnum;
use App\Enums\DocumentSignatureSentNotificationTypeEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
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
            $this->kafkaPublish('analytic_event', $logData);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData['esignMethod'], $documentSignatureSentId, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }
        $checkUserResponse = json_decode($this->checkUserSignature($setupConfig, $documentSignatureSent, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData['esignMethod']));
        if ($checkUserResponse->status_code == BsreStatusTypeEnum::RESPONSE_CODE_BSRE_ACCOUNT_OK()->value) {
            $logData = $this->setKafkaBsreAvailable($checkUserResponse);
            $this->kafkaPublish('analytic_event', $logData);
            $signature = $this->doSignature($setupConfig, $documentSignatureSent, $passphrase, $documentSignatureEsignData);

            return $signature;
        } else {
            return $this->invalidResponseCheckUserSignature($checkUserResponse, $documentSignatureSent, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData['esignMethod']);
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
        $pdfFile = $this->pdfFile($data, $setNewFileData['verifyCode'], $documentSignatureEsignData['esignMethod']);
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
            $data = $this->saveNewFile($response, $data, $setNewFileData, $documentSignatureEsignData['esignMethod']);
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
     * @param  enum $esignMethod
     * @return mixed
     */
    protected function pdfFile($data, $verifyCode, $esignMethod)
    {
        if ($data->documentSignature->has_footer == false) {
            $pdfFile = $this->addFooterDocument($data, $verifyCode, $esignMethod);
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
     * @param  enum $esignMethod
     * @return mixed
     */
    protected function addFooterDocument($data, $verifyCode, $esignMethod)
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
                'status' => 'ESIGN_FOOTER_FAILED_UNKNOWN',
                'esign_source_file' => $data->documentSignature->url,
                'esign_response' => $th,
                'message' => 'Gagal menambahkan QRCode dan text footer',
                'longMessage' => 'Gagal menambahkan QRCode dan text footer kedalam PDF, silahkan coba kembali'
            ];

            $this->kafkaPublish('analytic_event', $logData);

            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $esignMethod, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }
    }

    /**
     * saveNewFile
     *
     * @param  object $response
     * @param  collection $data
     * @param  array $setNewFileData
     * @param  array $esignMethod
     * @return collection
     */
    protected function saveNewFile($pdf, $data, $setNewFileData, $esignMethod)
    {
        //save to storage path for temporary file
        Storage::disk('local')->put($setNewFileData['newFileName'], $pdf->body());

        //find this document is not last on list
        $nextDocumentSent = $this->findNextDocumentSent($data);

        //transfer to existing service
        $response = $this->doTransferFile($data, $setNewFileData['newFileName'], $nextDocumentSent);
        if ($response->status() != Response::HTTP_OK) {
            $logData = [
                'message' => 'Gagal menyambung ke webhook API',
                'longMessage' => 'Gagal mengirimkan file tertandatangani ke webhook, silahkan coba kembali'
            ];
            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $esignMethod, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        } else {
            $data = $this->updateDocumentSentStatus($data, $setNewFileData, $nextDocumentSent, $esignMethod);
        }

        Storage::disk('local')->delete($setNewFileData['newFileName']);

        return $data;
    }

    /**
     * doTransferFile
     *
     * @param  collection $data
     * @param  string $newFileName
     *  @param collection $nextDocumentSent
     * @return mixed
     */
    protected function doTransferFile($data, $newFileName, $nextDocumentSent)
    {
        // setup body request
        $documentRequest = [
            'first_tier' => false,
            'last_tier' => false,
            'document_name' => $data->documentSignature->file // original name file before renamed
        ];
        // check if this esign is first tier
        if ($data->urutan == 1) {
            $documentRequest['first_tier'] = true;
        }

        if (!$nextDocumentSent && $data->documentSignature->documentSignatureType->is_mandatory_registered == false) {
            $documentRequest['last_tier'] = true;
            $documentRequest['document_name'] = $data->documentSignature->tmp_draft_file;
        }

        $fileSignatured = fopen(Storage::path($newFileName), 'r');
        /**
         * This code will running :
         * Transfer file to service existing
         * Remove original file (first tier)
         * Remove draft file (last tier) if document didn't required to register before download/distribute
        **/
        $response = Http::withHeaders([
            'Secret' => config('sikd.webhook_secret'),
        ])->attach(
            'signature',
            $fileSignatured,
            $newFileName
        )->post(config('sikd.webhook_url'), $documentRequest);

        return $response;
    }

    /**
     * updateDocumentSentStatus
     *
     * @param  collection $data
     * @param  array $setNewFileData
     * @param  collection $nextDocumentSent
     * @param  array $esignMethod
     * @return collection
     */
    protected function updateDocumentSentStatus($data, $setNewFileData, $nextDocumentSent, $esignMethod)
    {
        DB::beginTransaction();
        try {
            //change filename with _signed & update stastus
            if ($data->documentSignature->has_footer == false) {
                $this->updateDocumentSignatureNewFileAction($data->ttd_id, $setNewFileData);
            }

            //update status document sent to 1 (signed)
            $this->updateDocumentSignatureSentStatusAfterEsign($data, $esignMethod);

            //check if any next siganture require
            if ($nextDocumentSent) {
                $this->updateNextDocumentSent($$nextDocumentSent->id);
                //Send notification to next people
                $this->doSendNotification($nextDocumentSent->id, $esignMethod);
            } else { // if this is last people
                $this->updateDocumentSignatureLastPeopleAction($data->ttd_id);
                // update passed people at document signature list when last people already signed
                $this->updateDocumentSignatureSentMissedAction($data->ttd_id);
                //Send notification to sender
                $this->doSendForwardNotification($data->id, $data->receiver->PeopleName, $esignMethod);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            $logData = [
                'message' => 'Gagal menyimpan perubahan data',
                'longMessage' => $th->getMessage()
            ];
            // Set return failure esign
            $this->esignFailedExceptionResponse($logData, $esignMethod, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }

        return $data;
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
        ];

        $this->doSendNotificationDocumentSignature($sendToNotification, $esignMethod);
    }

    /**
     * doSendNotification
     *
     * @param  object $data
     * @param  enum $esignMethod
     * @return void
     */
    protected function doSendNotificationSelf($id, $esignMethod)
    {
        $sendToNotification = [
            'title' => 'TTE Berhasil',
            'body' => 'Anda telah berhasil melakukan TTE',
            'documentSignatureSentId' => $id,
            'target' => DocumentSignatureSentNotificationTypeEnum::RECEIVER(),
        ];

        $this->doSendNotificationDocumentSignature($sendToNotification, $esignMethod);
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
        ];

        $this->doSendNotificationDocumentSignature($sendToNotification, $esignMethod);
    }
}
