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
        $document = $this->getDocument($type, $id);
        if ($document) {
            $file = $this->getFile($document, $request->file);
            if (!$file) {
                return response()->json([
                    'message' => 'File not found'
                ], 404);
            }
            $filename = $document->file;
            $tempFile = tempnam(sys_get_temp_dir(), $filename);
            copy($file, $tempFile);
            return response()->download($tempFile, $filename);
        } else {
            return response()->json([
                'message' => 'Document not found'
            ], 404);
        }
    }

    /**
     * Get document by letter type.
     *
     * @param  String $type
     * @param  String $id
     * @return Object
     */
    protected function getDocument($type, $id)
    {
        $document = match (strtoupper($type)) {
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
}
