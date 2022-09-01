<?php

namespace App\Enums;

use Spatie\Enum\Enum;

/**
 * @method static self HIDE()
 * @method static self SHOW()
 */

class SignatureVisibleTypeEnum extends Enum
{
    protected static function values(): array
    {
        return [
            'HIDE' => 0,
            'SHOW' => 1,
        ];
    }
}
