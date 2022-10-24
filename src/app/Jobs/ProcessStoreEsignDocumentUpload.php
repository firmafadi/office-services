<?php

namespace App\Jobs;

use App\GraphQL\Types\DocumentSignatureType;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;
use App\Models\DocumentSignatureType as ModelsDocumentSignatureType;
use App\Models\TicketDocumentUpload;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStoreEsignDocumentUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $storeDocumentData;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($storeDocumentData)
    {
        $this->storeDocumentData = $storeDocumentData;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $documentSignature = $this->storeDocumentSignature();
        $documentSignatureSent = $this->storeDocumentSignatureSent($documentSignature);

        $ticketDocument = TicketDocumentUpload::where('id', $this->storeDocumentData['request'])->update([
            'ttd_id' => $documentSignature->id
        ]);
    }

    /**
     * storeDocumentSignature
     *
     * @return collection
     */
    protected function storeDocumentSignature()
    {
        $documentSignature = new DocumentSignature();
        $documentSignature->nama_file       = $this->storeDocumentData['request']->file('file')->getClientOriginalName();
        $documentSignature->file            = $this->storeDocumentData['file'];
        $documentSignature->tmp_draft_file  = $this->storeDocumentData['draft'];
        $documentSignature->ukuran          = $this->storeDocumentData['request']->file('file')->getSize();
        $documentSignature->tanggal         = Carbon::now();
        $documentSignature->PeopleID        = 1; // TODO: CHANGE CREATOR ID (1) WITH USER ID FROM KEYCLOACK
        $documentSignature->type_id         = $this->storeDocumentData['request']->document_esign_type_id;
        $documentSignature->is_forwardable  = true;
        $documentSignature->is_registered   = false;
        $documentSignature->save();

        return $documentSignature;
    }

    /**
     * storeDocumentSignatureSent
     *
     * @param  mixed $collection
     * @return boolean
     */
    protected function storeDocumentSignatureSent($documentSignature)
    {
        $documentType = ModelsDocumentSignatureType::find($this->storeDocumentData['request']->document_esign_type_id);
        $countSigners = count($this->storeDocumentData['request']->nip);

        foreach ($this->storeDocumentData['request']->nip as $key => $receiver) {
            $sort = $key + 1;
            if ($documentType->is_missable == true) {
				$next = ($sort == 1 || $sort == $countSigners) ? 1 : 0; // first signer or last signer
			} else {
				$next = ($sort == 1) ? 1 : 0; // just first signer
			}

            $documentSignatureSent = new DocumentSignatureSent();
            $documentSignatureSent->ttd_id              = $documentSignature->id;
            $documentSignatureSent->tgl                 = Carbon::now();
            $documentSignatureSent->PeopleID            = 1; // TODO: CHANGE CREATOR ID (1) WITH USER ID FROM KEYCLOACK
            $documentSignatureSent->PeopleIDTujuan      = $receiver;
            $documentSignatureSent->catatan             = $this->storeDocumentData['request']->note;
            $documentSignatureSent->urutan              = $sort;
            $documentSignatureSent->status              = 0;
            $documentSignatureSent->next                = $next;
            $documentSignatureSent->previous_sender_id  = ($key != 0) ? $this->storeDocumentData['request']->nip[$key - 1] : 1; // TODO: CHANGE CREATOR ID (1) WITH USER ID FROM KEYCLOACK
            $documentSignatureSent->forward_receiver_id = ($sort != $countSigners) ? $this->storeDocumentData['request']->nip[$key + 1] : 1; // TODO: CHANGE CREATOR ID (1) WITH USER ID FROM KEYCLOACK

            $documentSignatureSent->save();
        }

        return true;
    }
}
