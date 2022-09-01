<?php

namespace App\Http\Controllers\V1;

use App\Enums\DocumentDownloadFileTypeEnum;
use App\Enums\DocumentDownloadListTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignature;
use App\Models\Inbox;
use Illuminate\Http\Request;

class DocumentDownloadController extends Controller
{
    use KafkaTrait;

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  String $type
     * @param  String $id
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $type, $id)
    {
        $file = null;
        $document = $this->getDocument($type, $id);
        if ($document) {
            $file = $this->getFile($document, $request->file);
            if ($file) {
                $filename = $document->file;
                $tempFile = tempnam(sys_get_temp_dir(), $filename);
                copy($file, $tempFile);

                $this->log($file, $type, $request->file);
                return response()->download($tempFile, $filename);
            }
        }

        if (!$document || !$file) {
            $this->log($file, $type, $request->file);
            return response()->json([
                'message' => 'File not found'
            ], 404);
        }
    }

    /**
     * Get document by letter type.
     *
     * @param  String $documentType
     * @param  String $id
     * @return Object
     */
    protected function getDocument($documentType, $id)
    {
        $document = match (strtoupper($documentType)) {
            DocumentDownloadListTypeEnum::SIGNATURE()->value => DocumentSignature::find($id),
            DocumentDownloadListTypeEnum::INBOX()->value => Inbox::find($id)
        };
        return $document;
    }

    /**
     * Get file by file type.
     *
     * @param  Object $document
     * @param  String $fileType
     * @return String
     */
    protected function getFile($document, $fileType)
    {
        $file = match (strtoupper($fileType)) {
            DocumentDownloadFileTypeEnum::ATTACHMENT()->value => $document->getAttachmentAttribute(),
            default => $document->getUrlPublicAttribute()
        };
        return $file;
    }

    /**
     * Kafka logging.
     *
     * @param  String $file
     * @param  String $documentType
     * @param  String $fileType
     * @param  KafkaStatusTypeEnum $status
     * @return Void
     */
    protected function log($file, $documentType, $fileType)
    {
        $logData['event'] = match (strtoupper($documentType)) {
            DocumentDownloadListTypeEnum::SIGNATURE()->value => match (strtoupper($fileType)) {
                DocumentDownloadFileTypeEnum::ATTACHMENT()->value => 'download_signed_letter_attachment',
                default => 'download_signed_letter'
            },
            default => match (strtoupper($fileType)) {
                DocumentDownloadFileTypeEnum::ATTACHMENT()->value => 'download_letter_attachment',
                default => 'download_letter'
            },
        };

        if ($file) {
            $logData['status'] = KafkaStatusTypeEnum::SUCCESS();
            $logData['letter']['file'] = $file;
        } else {
            $logData['status'] = KafkaStatusTypeEnum::FAILED();
            $logData['message'] = 'file not found';
        }

        $this->kafkaPublish('analytic_event', $logData);
    }
}
