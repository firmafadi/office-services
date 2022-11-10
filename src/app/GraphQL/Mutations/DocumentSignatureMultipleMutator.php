<?php

namespace App\GraphQL\Mutations;

use App\Enums\MediumTypeEnum;
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
        $documentsignatureSents  = explode(', ', Arr::get($args, 'input.documentSignatureSentIds'));

        $requestInput = [
            'documents' => $documentsignatureSents,
            'passphrase' => $passphrase,
            'fcm_token' => $fcmToken,
            'is_self_sign' => false,
            'medium' => MediumTypeEnum::MOBILE()
        ];

        return $this->setupMultiFileEsignDocumentSignature($requestInput);

    }
}
