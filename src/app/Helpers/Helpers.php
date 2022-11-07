<?php

use Carbon\Carbon;

function setDateTimeNowValue()
{
    return Carbon::now()->setTimezone(config('sikd.timezone_server'));
}

function parseDateTimeValue($value)
{
    return Carbon::parse($value)->addHours(config('sikd.timezone_server'));
}

function parseDateTimeFormat($value, $format)
{
    return parseDateTimeValue($value)->format($format);
}

function parseSetLocaleDate($value, $locale, $format)
{
    return Carbon::parse($value)->locale($locale)->translatedFormat($format);
}

function str_contains_all(array $needles, $word) {
    foreach ($needles as $needle) {
        if (strpos($needle, $word) !== false) {
            return true;
            break;
        }
    }
    return false;
}
