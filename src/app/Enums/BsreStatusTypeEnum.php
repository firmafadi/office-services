<?php

namespace App\Enums;

use Spatie\Enum\Enum;

/**
 * @method static self RESPONSE_CODE_BSRE_ACCOUNT_OK()
 * @method static self RESPONSE_CODE_BSRE_ACCOUNT_NOT_REGISTERED()
 * @method static self RESPONSE_CODE_BSRE_ACCOUNT_ESIGN_NOT_ACTIVE()
 */

class BsreStatusTypeEnum extends Enum
{
    protected static function values(): array
    {
        return [
            'RESPONSE_CODE_BSRE_ACCOUNT_OK' => 1111,
            'RESPONSE_CODE_BSRE_ACCOUNT_NOT_REGISTERED' => 1110,
            'RESPONSE_CODE_BSRE_ACCOUNT_ESIGN_NOT_ACTIVE' => 2021,
        ];
    }
}
