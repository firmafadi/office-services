<?php

namespace App\Http\Controllers\V1;

use App\Enums\KafkaStatusTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignature;
use Illuminate\Http\Request;

class SignaturedDocumentDownloadController extends Controller
{
    use KafkaTrait;

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  String $id
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id)
    {
        $logData = [
            'event' => 'download_signed_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
        ];

        $document = DocumentSignature::find($id);
        if ($document) {
            $file = $this->generateFile($document);
            $logData['letter']['file'] = $file;
            $this->kafkaPublish('analytic_event', $logData);
            return response()->json([
                'file' => $file
            ], 200);
        } else {
            $logData['status'] = KafkaStatusTypeEnum::FAILED();
            $logData['message'] = 'Document not found';
            $this->kafkaPublish('analytic_event', $logData);
            return response()->json([
                'message' => 'Document not found'
            ], 404);
        }
    }

    /**
     * Generate document file.
     *
     * @param  DocumentSignature $document
     * @return String
     */
    protected function generateFile($document)
    {
        $path = config('sikd.base_path_file');
        $file = match ($document->is_registered) {
            (int) true => $path . 'ttd/sudah_ttd/' . $document->file,
            (int) false => $file = $path . 'ttd/draft/' . $document->tmp_draft_file,
            default => $this->generateFileWhichRegisteredStatusNull($document)
        };
        return $file;
    }

    /**
     * Generate document file which has null registered status.
     *
     * @param  DocumentSignature $document
     * @return String
     */
    protected function generateFileWhichRegisteredStatusNull($document)
    {
        $path = config('sikd.base_path_file');
        $file = $document->checkFile($path . 'ttd/draft/' . $document->tmp_draft_file);
        if ($file === false) {
            $file = $document->checkFile($path . 'ttd/sudah_ttd/' . $document->file);
            if ($file === false) { // handle for old data before draft schema implemented
                $file = $path . 'ttd/blm_ttd/' . $document->file;
            }
        }
        return $file;
    }
}
