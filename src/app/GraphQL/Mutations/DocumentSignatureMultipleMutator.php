<?php

namespace App\GraphQL\Mutations;

use App\Enums\SignatureQueueTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\SignDocumentSignatureTrait;
use App\Jobs\ProcessMultipleEsignDocument;
use App\Models\DocumentSignatureSent;
use Illuminate\Support\Arr;

class DocumentSignatureMultipleMutator
{
    use SignDocumentSignatureTrait;

    /**
     * @param $rootValue
     * @param $args
     *
     * @throws \Exception
     *
     * @return array
     */
    public function signature($rootValue, array $args)
    {
        $passphrase                     = Arr::get($args, 'input.passphrase');
        $splitDocumentSignatureSentIds  = explode(', ', Arr::get($args, 'input.documentSignatureSentIds'));
        $userId                         = auth()->user()->PeopleId;

        if (count($splitDocumentSignatureSentIds) > config('sikd.maximum_multiple_esign')) {
            throw new CustomException(
                'Batas maksimal untuk melakukan multi-file esign adalah ' . config('sikd.maximum_multiple_esign') . ' dokumen',
                'Permintaan Anda melewati batas maksimal untuk melakukan multi-file esign.'
            );
        }

        // set rule for queue only failed or null and non signed/rejected data
        $documentSignatureSents = DocumentSignatureSent::whereIn('id', $splitDocumentSignatureSentIds)
                                                        ->where('status', SignatureStatusTypeEnum::WAITING()->value)
                                                        ->where(function ($query) {
                                                            $query->whereNull('progress_queue')
                                                                  ->orWhere('progress_queue', SignatureQueueTypeEnum::FAILED());
                                                        })->get();

        if ($documentSignatureSents->isEmpty()) {
            throw new CustomException(
                'Antrian dokumen telah dieksekusi',
                'Antrian dokumen telah dieksekusi oleh sistem, silahkan menunggu hingga selesai.'
            );
        }

        foreach ($documentSignatureSents as $documentSignatureSent) {
            ProcessMultipleEsignDocument::dispatch($documentSignatureSent->id, $passphrase, $userId);
        }

        return $documentSignatureSents;
    }
}
