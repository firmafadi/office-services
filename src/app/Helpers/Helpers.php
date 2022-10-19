<?php

use Carbon\Carbon;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

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

function allowedIssuers()
{
    return array(config('keycloak.iss'));
}

function parseJWTToken($token)
{
    try {
        JWT::$leeway = config('jwt.leeway');

        $jwks_response = file_get_contents(config('keycloak.base_url') . '/auth/realms/' . config('keycloak.realm') . '/protocol/openid-connect/certs');
        $jwks = json_decode($jwks_response, true);

        $result = JWT::decode($token, JWK::parseKeySet($jwks));

        if (!in_array($result->iss, allowedIssuers())) {
            throw new Exception("Unknown Issuer " . $result->iss);
        }

        return $result;
    } catch (\Throwable $th) {
        throw new Exception($th->getMessage());
    }
}
