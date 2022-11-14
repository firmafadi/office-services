<?php

namespace App\GraphQL\Mutations;

use App\Enums\MediumTypeEnum;
use App\Http\Traits\SignInitDocumentSignatureTrait;
use Illuminate\Support\Arr;

class DocumentSignatureMultipleMutator
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
        $fcmToken                       = Arr::get($args, 'input.fcm_token');
        $passphrase                     = Arr::get($args, 'input.passphrase');
        $fcmToken                       = (isset($fcmToken)) ? $fcmToken : null;
        $documentsignatureSents  = explode(', ', Arr::get($args, 'input.documentSignatureSentIds'));

        $requestInput = [
            'id' => $documentsignatureSents,
            'passphrase' => $passphrase,
            'fcmToken' => $fcmToken,
            'isSignedSelf' => false,
            'medium' => MediumTypeEnum::MOBILE(),
        ];

        $checkMaximumMultipleEsign = $this->checkMaximumMultipleEsign($requestInput['id']);
        if ($checkMaximumMultipleEsign != true) {
            return $checkMaximumMultipleEsign;
        }

        return $this->setupMultiFileEsignDocument($requestInput);

    }
}
