<?php

namespace App\GraphQL\Mutations;

use App\Http\Traits\SignInitDocumentSignatureTrait;
use Illuminate\Support\Arr;

class DocumentSignatureMutator
{
    use SignInitDocumentSignatureTrait;

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
        $documentSignatureSentId    = Arr::get($args, 'input.documentSignatureSentId');
        $passphrase                 = Arr::get($args, 'input.passphrase');

        $requestInput = [
            'id' => $documentSignatureSentId,
            'passphrase' => $passphrase,
            'isSignedSelf' => false,
        ];

        return $this->setupSingleFileEsignDocumentSignature($requestInput);
    }
}
