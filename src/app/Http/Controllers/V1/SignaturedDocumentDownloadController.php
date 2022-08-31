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
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id)
    {
        $document = DocumentSignature::find($id);
        if (!$document) {
            $this->kafkaPublish('analytic_event', [
                'event' => 'download_signed_letter',
                'status' => KafkaStatusTypeEnum::FAILED()
            ]);

            return response()->json([
                'message' => 'Document not found'
            ], 404);
        }

        $path = config('sikd.base_path_file');
        // New data with registered flow
        if ($document->is_registered === true) {
            $file = $path . 'ttd/sudah_ttd/' . $document->file;
        } elseif ($document->is_registered === false && $document->is_registered !== null) {
            $file = $path . 'ttd/draft/' . $document->tmp_draft_file;
        } else {
            // Old data without registered flow
            $file = $document->checkFile($path . 'ttd/draft/' . $document->tmp_draft_file);
            if ($file === false) {
                $file = $document->checkFile($path . 'ttd/sudah_ttd/' . $document->file);
                if ($file === false) { // handle for old data before draft schema implemented
                    $file = $path . 'ttd/blm_ttd/' . $document->file;
                }
            }
        }

        $this->kafkaPublish('analytic_event', [
            'event' => 'download_signed_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'letter' => [
                'file' => $file
            ]
        ]);

        return response()->json([
            'file' => $file
        ], 200);
    }
}
