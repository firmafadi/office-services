<?php

namespace App\Enums;

use Spatie\Enum\Enum;

/**
 * @method static self SUCCESS()
 * @method static self FAILED()
 * @method static self DOCUMENT_APPROVE_FAILED_ALREADY_SIGNED()
 * @method static self DOCUMENT_APPROVE_FAILED_NOFILE()
 * @method static self DOCUMENT_APPROVE_FAILED_NIK()
 * @method static self ESIGN_FAILED()
 * @method static self ESIGN_SUCCESS()
 * @method static self LOGIN_SUCCESS()
 * @method static self LOGIN_INVALID_CREDENTIALS()
 * @method static self ESIGN_TRANSFER_FAILED_FROM_MOBILE()
 * @method static self ESIGN_FOOTER_FAILED_UNKNOWN()
 * @method static self ESIGN_INVALID_UPDATE_STATUS_AND_DATA()

 */

final class KafkaStatusTypeEnum extends Enum
{
}
