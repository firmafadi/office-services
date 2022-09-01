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
            $file = $document->getUrlPublicAttribute();
            $logData['letter']['file'] = $file;
            $this->kafkaPublish('analytic_event', $logData);
            // download document file
            $filename = $document->file;
            $tempFile = tempnam(sys_get_temp_dir(), $filename);
            copy($file, $tempFile);
            return response()->download($tempFile, $filename);
        } else {
            $logData['status'] = KafkaStatusTypeEnum::FAILED();
            $logData['message'] = 'Document not found';
            $this->kafkaPublish('analytic_event', $logData);
            return response()->json([
                'message' => 'Document not found'
            ], 404);
        }
    }
}
