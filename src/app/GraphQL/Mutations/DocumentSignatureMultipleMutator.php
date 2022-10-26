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
        $fcmToken                       = Arr::get($args, 'input.fcm_token');
        $fcmToken                       = (isset($fcmToken)) ? $fcmToken : null;
        $splitDocumentSignatureSentIds  = explode(', ', Arr::get($args, 'input.documentSignatureSentIds'));
        $userId                         = auth()->user()->PeopleId;

        if (count($splitDocumentSignatureSentIds) > config('sikd.maximum_multiple_esign')) {
            throw new CustomException(
                'Batas maksimal untuk melakukan multi-file esign adalah ' . config('sikd.maximum_multiple_esign') . ' dokumen',
                'Permintaan Anda melewati batas maksimal untuk melakukan multi-file esign.'
            );
        }

        // set rule for queue only failed or null and non signed/rejected data
        $documentSignatureSents = $this->listDocumentSignatureMultiple($splitDocumentSignatureSentIds);

        if ($documentSignatureSents->isEmpty()) {
            throw new CustomException(
                'Antrian dokumen telah dieksekusi',
                'Antrian dokumen telah dieksekusi oleh sistem, silahkan menunggu hingga selesai.'
            );
        }

        $this->doDocumentSignatureMultiple($documentSignatureSents, $passphrase, $userId, $fcmToken);

        return $documentSignatureSents;
    }

    /**
     * listDocumentSignatureMultiple
     *
     * @param  array $splitDocumentSignatureSentIds
     * @return collection
     */
    protected function listDocumentSignatureMultiple($splitDocumentSignatureSentIds)
    {
        $query = DocumentSignatureSent::whereIn('id', $splitDocumentSignatureSentIds)
            ->where('status', SignatureStatusTypeEnum::WAITING()->value)
            ->where(function ($query) {
                $query->whereNull('progress_queue')
                    ->orWhere('progress_queue', SignatureQueueTypeEnum::FAILED());
            })->get();

        return $query;
    }

    /**
     * doDocumentSignatureMultiple
     *
     * @param  array $documentSignatureSents
     * @param  string $passphrase
     * @param  integer $userId
     * @param  string $fcmToken
     * @return array
     */
    protected function doDocumentSignatureMultiple($documentSignatureSents, $passphrase, $userId, $fcmToken)
    {
        foreach ($documentSignatureSents as $documentSignatureSent) {
            ProcessMultipleEsignDocument::dispatch($documentSignatureSent->id, $passphrase, $userId, $fcmToken);
        }

        return $documentSignatureSents;
    }
}
