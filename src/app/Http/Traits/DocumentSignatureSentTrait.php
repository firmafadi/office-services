<?php

namespace App\Http\Traits;

use App\Models\DocumentSignatureSent;

/**
 * Setup configuration for signature document
 */
trait DocumentSignatureSentTrait
{
    /**
     * findNextDocumentSent
     *
     * @param  collection $data
     * @return collection
     */
    protected function findNextDocumentSent($data)
    {
        $nextDocumentSent = DocumentSignatureSent::where('ttd_id', $data->ttd_id)
                                                    ->where('urutan', $data->urutan + 1)
                                                    ->first();
        return $nextDocumentSent;
    }
}
