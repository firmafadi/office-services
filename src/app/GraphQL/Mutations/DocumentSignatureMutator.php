<?php

namespace App\GraphQL\Mutations;

use App\Enums\MediumTypeEnum;
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

        $requestInput = [
            'document' => $documentSignatureSentId,
            'passphrase' => $passphrase,
            'is_self_sign' => false,
            'medium' => MediumTypeEnum::MOBILE()
        ];

        return $this->setupSingleFileEsignDocumentSignature($requestInput);
    }
}
