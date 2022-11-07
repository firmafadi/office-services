<?php

namespace App\GraphQL\Mutations;

use App\Http\Traits\SetupEsignDocumentSignatureTrait;
use Illuminate\Support\Arr;

class DocumentSignatureMultipleMutator
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
        $fcmToken                       = Arr::get($args, 'input.fcm_token');
        $passphrase                     = Arr::get($args, 'input.passphrase');
        $fcmToken                       = (isset($fcmToken)) ? $fcmToken : null;
        $arrayOfDocumentSignatureSents  = explode(', ', Arr::get($args, 'input.documentSignatureSentIds'));

        return $this->setupMultiFileEsignDocumentSignature($arrayOfDocumentSignatureSents, $passphrase, $fcmToken);

    }
}
