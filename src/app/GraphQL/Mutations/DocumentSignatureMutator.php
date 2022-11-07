<?php

namespace App\GraphQL\Mutations;

use App\Http\Traits\SetupEsignDocumentSignatureTrait;
use Illuminate\Support\Arr;

class DocumentSignatureMutator
{
    use SetupEsignDocumentSignatureTrait;

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

        return $this->setupSingleFileEsignDocumentSignature($documentSignatureSentId, $passphrase);
    }
}
