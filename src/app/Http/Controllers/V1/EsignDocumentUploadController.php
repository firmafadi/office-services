<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEsignDocumentUploadRequest;
use App\Http\Resources\TicketDocumentUploadResource;
use App\Jobs\ProcessStoreEsignDocumentUpload;
use App\Models\TicketDocumentUpload;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class EsignDocumentUploadController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function upload(StoreEsignDocumentUploadRequest $request)
    {
        $transferDocumentFile = $this->setDocumentFileUpload($request);
        if ($transferDocumentFile->status() != Response::HTTP_OK) {
            return response()->json([
                'message' => 'Failed upload document file',
                'status' => false,
            ], 400);
        }

        $documentFile = json_decode($transferDocumentFile->getBody()->getContents());

        $documentAttachment = null;
        if ($request->hasFile('attachment')) {
            $transferDocumentAttachmentFile = $this->setDocumentFileAttachmentUpload($request);
            if ($transferDocumentAttachmentFile->status() != Response::HTTP_OK) {
                return response()->json([
                    'message' => 'Failed upload document attachment',
                    'status' => false,
                ], 400);
            }

            $documentAttachment = json_decode($transferDocumentAttachmentFile->getBody()->getContents());
        }

        $createTicket = new TicketDocumentUpload();
        $createTicket->ticket_number = uniqid();
        $createTicket->save();

        $storeDocumentData = [
            'id'         => $createTicket->id,
            'request'    => $request,
            'file'       => $documentFile->data->file,
            'draft'      => $documentFile->data->draft,
            'attachment' => ($documentAttachment != null) ? $documentAttachment->data->attachment : null,
        ];

        ProcessStoreEsignDocumentUpload::dispatch($storeDocumentData);

        return new TicketDocumentUploadResource($createTicket);
    }

    /**
     * setDocumentFileUpload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return object
     */
    protected function setDocumentFileUpload($request)
    {
        $fileName = $request->file('file')->getClientOriginalName();
        $request->file('file')->storeAs('esign_document', $fileName);
        $fileUpload = Storage::disk('local')->get('esign_document/' . $fileName);

        $bodyRequest = ['document_esign_type_id' => $request->document_esign_type_id];
        $response = $this->doTransferFile('document_esign_file', $fileUpload, $fileName, $bodyRequest);

        Storage::disk('local')->delete('esign_document/' . $fileName);

        return $response;
    }

    /**
     * setDocumentFileAttachmentUpload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return object
     */
    protected function setDocumentFileAttachmentUpload($request)
    {
        $fileNameAttachment = $request->file('attachment')->getClientOriginalName();
        $request->file('file')->storeAs('esign_document/attachment', $fileNameAttachment);
        $fileUploadAttachment = Storage::disk('esign_document/attachment')->get('esign_document/attachment/' . $fileNameAttachment);

        $response = $this->doTransferFile('document_esign_attachment', $fileUploadAttachment, $fileNameAttachment);

        Storage::disk('local')->delete('esign_document/attachment/' . $fileUploadAttachment);

        return $response;
    }

    /**
     * doTransferFile
     *
     * @param  string $type
     * @param  mixed $fileUpload
     * @param  string $fileName
     * @param  array $bodyRequest
     * @return object
     */
    protected function doTransferFile($type, $fileUpload, $fileName, $bodyRequest = null)
    {
        try {
            $response = Http::withHeaders([
                'Secret' => config('sikd.webhook_secret'),
            ])->attach($type, $fileUpload, $fileName)
            ->post(config('sikd.webhook_url'), $bodyRequest);

            return $response;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed connect to storage on upload file ' . $type,
                'status' => false,
            ], 400);
        }
    }
}
