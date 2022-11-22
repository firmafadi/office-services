<?php

namespace App\Http\Traits;

use App\Enums\KafkaStatusTypeEnum;
use App\Enums\MediumTypeEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Setup configuration for signature document
 */
trait SignActionDocumentSignatureTrait
{
    use SignUpdateDataDocumentSignatureTrait;
    use KafkaTrait;
    use KafkaSignActionDocumentSignatureTrait;
    use SendNotificationTrait;
    use SignatureTrait;
    use SignNotificationDocumentSignatureTrait;

    /**
     * initProcessSignDocumentSignature
     *
     * @param  array $documentSignatureEsignData
     * @param  array $header
     * @return void
     */
    protected function initProcessSignDocumentSignature($documentSignatureEsignData)
    {
        if ($documentSignatureEsignData['isSignedSelf'] == true) {
            $data = DocumentSignature::findOrFail($documentSignatureEsignData['id']);
        } else {
            $data = DocumentSignatureSent::findOrFail($documentSignatureEsignData['id']);
        }

        if ($documentSignatureEsignData['esignMethod'] == SignatureMethodTypeEnum::MULTIFILE()) {
            $data->progress_queue = SignatureQueueTypeEnum::PROCESS();
            $data->save();
        }

        return $this->processSignDocumentSignature($data, $documentSignatureEsignData);
    }

    /**
     * processSignDocumentSignature
     *
     * @param  mixed $data
     * @param  array $documentSignatureEsignData
     * @param  array $header
     * @return void
     */
    protected function processSignDocumentSignature($data, $documentSignatureEsignData)
    {
        $setupConfig = $this->setupConfigSignature($documentSignatureEsignData['userId']); // add user id for queue
        $documentData = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data : $data->documentSignature;

        $file = $this->fileExist($documentData->url);
        if (!$file) {
            $logData = $this->setKafkaDocumentNotFoundSignatureSent();
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $documentData->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }

        $signature = $this->doSignature($setupConfig, $data, $documentSignatureEsignData);
        return $signature;
    }

    /**
     * doSignature
     *
     * @param  array $setupConfig
     * @param  collection $data
     * @param  array $documentSignatureEsignData
     * @return collection
     */
    protected function doSignature($setupConfig, $data, $documentSignatureEsignData)
    {
        $documentData = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data : $data->documentSignature;
        $setNewFileData = $this->setNewFileData($documentData);
        $pdfFile = $this->pdfFile($documentData, $setNewFileData['verifyCode'], $documentSignatureEsignData);
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $setupConfig['auth'], 'Cookie' => 'JSESSIONID=' . $setupConfig['cookies'],
        ])->attach('file', $pdfFile, $documentData->file)->post($setupConfig['url'] . '/api/sign/pdf', [
            'nik'           => $setupConfig['nik'],
            'passphrase'    => $documentSignatureEsignData['passphrase'],
            'tampilan'      => 'invisible',
            'image'         => 'false',
        ]);
        if ($response->status() != Response::HTTP_OK) {
            $bodyResponse = json_decode($response->body());
            $this->setPassphraseSessionLog($response, $documentData, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData);
            $logData = [
                'message' => 'Gagal melakukan tanda tangan elektronik',
                'longMessage' => $bodyResponse->error
            ];
            // Set return failure esign
            $responseAfterEsign = $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        } else {
            //Save new file & update status
            $responseAfterEsign = $this->saveNewFile($response, $data, $setNewFileData, $documentSignatureEsignData);
            $this->setPassphraseSessionLog($response, $data, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT(), $documentSignatureEsignData);
        }

        $this->checkIsLastItemQueueRedis($data->id, $documentSignatureEsignData);
        return $responseAfterEsign;
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
            'newFileName' => $data->document_file_name,
            'verifyCode'  => strtoupper(substr(sha1(uniqid(mt_rand(), true)), 0, 10))
        ];

        return $newFileData;
    }

    /**
     * pdfFile
     *
     * @param  collection $documentData
     * @param  string $verifyCode
     * @param  array $documentSignatureEsignData
     * @return mixed
     */
    protected function pdfFile($documentData, $verifyCode, $documentSignatureEsignData)
    {
        if ($documentData->has_footer == false) {
            $pdfFile = $this->addFooterDocument($documentData, $verifyCode, $documentSignatureEsignData);
        } else {
            $pdfFile = file_get_contents($documentData->url);
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
     * @param  collection  $documentData
     * @param  string $verifyCode
     * @param  array $documentSignatureEsignData
     * @return mixed
     */
    protected function addFooterDocument($documentData, $verifyCode, $documentSignatureEsignData)
    {
        try {
            $addFooter = Http::attach(
                'pdf',
                file_get_contents($documentData->url),
                $documentData->file
            )->post(config('sikd.add_footer_url'), [
                'qrcode' => config('sikd.url') . 'verification/document/tte/' . $verifyCode . '?source=qrcode',
                'category' => $documentData->documentSignatureType->document_paper_type,
                'code' => $verifyCode
            ]);

            return $addFooter;
        } catch (\Throwable $th) {
            $logData = [
                'event' => 'esign_FOOTER_pdf',
                'status' => KafkaStatusTypeEnum::ESIGN_FOOTER_FAILED_UNKNOWN(),
                'esign_source_file' => $documentData->url,
                'esign_response' => $th,
                'message' => 'Gagal menambahkan QRCode dan text footer',
                'longMessage' => 'Gagal menambahkan QRCode dan text footer kedalam PDF, silahkan coba kembali'
            ];

            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $documentData->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
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
        $documentData = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data :$data->documentSignature;
        //save to storage path for temporary file
        Storage::disk('local')->put($setNewFileData['newFileName'], $pdf->body());

        $nextDocumentSent = false;
        if ($documentSignatureEsignData['isSignedSelf'] == false) {
            //find this document is not last on list
            $nextDocumentSent = $this->findNextDocumentSent($data);
        }

        //transfer to existing service
        $response = $this->doTransferFile($data, $setNewFileData['newFileName'], $documentSignatureEsignData, $nextDocumentSent);
        Storage::disk('local')->delete($setNewFileData['newFileName']);

        if ($response->status() != Response::HTTP_OK) {
            $logData = $this->logInvalidTransferFile('esign_transfer_pdf', $documentData->url, $response);
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        } else {
            return $this->doUpdateStatusOfDocument($data, $setNewFileData, $documentSignatureEsignData, $nextDocumentSent);
        }
    }

    /**
     * doTransferFile
     *
     * @param collection $data
     * @param string $newFileName
     * @param mixed $nextDocumentSent (collection / boolean false)
     * @param array $documentSignatureEsignData
     * @return mixed
     */
    protected function doTransferFile($data, $newFileName, $documentSignatureEsignData, $nextDocumentSent)
    {
        $documentData = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data :$data->documentSignature;
        try {
            $documentRequest = $this->setupBodyRequestTransferFile($data, $documentData, $documentSignatureEsignData, $nextDocumentSent);

            $fileSignatured = fopen(Storage::path($newFileName), 'r');
            $response = Http::withHeaders([
                'Secret' => config('sikd.webhook_secret'),
            ])->attach('signature', $fileSignatured, $newFileName)->post(config('sikd.webhook_url'), $documentRequest);

            return $response;
        } catch (\Throwable $th) {
            $logData = $this->logInvalidConnectTransferFile('esign_transfer_pdf', $documentData->url, $th);
            $this->kafkaPublish('analytic_event', $logData);
            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $documentData->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
        }
    }

    /**
     * setupBodyRequestTransferFile
     *
     * @param  collection $data
     * @param  collection $documentData
     * @param  array $documentSignatureEsignData
     * @param  mixed $nextDocumentSent (collection / boolean false when isSignedSelf = true)
     * @return array
     */
    protected function setupBodyRequestTransferFile($data, $documentData, $documentSignatureEsignData, $nextDocumentSent)
    {
        $documentRequest = [
            'first_tier' => ($documentSignatureEsignData['isSignedSelf'] == true) ? true : false, // true => Remove original file (first tier)
            'last_tier' => false, // true => Remove draft file (last tier) if document didn't required to register before download/distribute
            'document_name' => $documentData->file // original name file before renamed
        ];

        if ($documentSignatureEsignData['isSignedSelf'] == false) {
            if ($data->urutan == 1) {
                $documentRequest['first_tier'] = true;
            }
        }

        if (!$nextDocumentSent && !$documentData->documentSignatureType->is_mandatory_registered) {
            $documentRequest['last_tier'] = true;
            $documentRequest['document_name'] = $documentData->tmp_draft_file;
        }

        return $documentRequest;
    }

    protected function doUpdateStatusOfDocument($data, $setNewFileData, $documentSignatureEsignData, $nextDocumentSent)
    {
        DB::beginTransaction();
        try {
            if ($documentSignatureEsignData['isSignedSelf'] == true) {
                return $this->updateSelfDocumentSentStatus($data, $setNewFileData, $documentSignatureEsignData);
            } else {
                return $this->updateDocumentSentStatus($data, $setNewFileData, $nextDocumentSent, $documentSignatureEsignData);
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            $documentData = ($documentSignatureEsignData['isSignedSelf'] == true) ? $data : $data->documentSignature;
            $logData = $this->setLogFailedUpdateDataAfterEsign($documentData, $th);
            $this->kafkaPublish('analytic_event', $logData, $documentSignatureEsignData['header']);

            // Set return failure esign
            return $this->esignFailedExceptionResponse($logData, $documentSignatureEsignData, $data->id, SignatureDocumentTypeEnum::UPLOAD_DOCUMENT());
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
        //update document after esign (set if new file or update last activity)
        $this->updateDocumentSignatureAfterEsign($data, $setNewFileData, $documentSignatureEsignData);
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
        $updateData = DocumentSignatureSent::where('id', $data->id)->first();
        return $updateData;
    }

    /**
     * updateSelfDocumentSentStatus
     *
     * @param  collection $data
     * @param  array $setNewFileData
     * @param  array $documentSignatureEsignData
     * @return collection
     */
    protected function updateSelfDocumentSentStatus($data, $setNewFileData, $documentSignatureEsignData)
    {
        //update document after esign (set if new file or update last activity)
        $this->updateDocumentSignatureAfterEsign($data, $setNewFileData, $documentSignatureEsignData);
        $updateData = DocumentSignature::where('id', $data->id)->first();
        return $updateData;
    }
}
