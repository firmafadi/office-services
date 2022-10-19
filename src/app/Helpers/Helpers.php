<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

function IsAudienceValid($allowedAudiens, $audiences)
{
    $audienceValidation = function ($allowedAudiens, $audience) {
        return in_array($audience, $allowedAudiens);
    };

    if (!is_array($audiences)) {
        return $audienceValidation($allowedAudiens, $audiences);
    }

    foreach ($audiences as $audience) {
        $isAudienceValid = $audienceValidation($allowedAudiens, $audience);

        if (!$isAudienceValid) {
            return false;
        }
    }

    return true;
}

function parseJWTToken($token)
{
    try {
        JWT::$leeway = config('jwt.leeway');

        $allowedIssuers = explode(',', config('keycloak.iss'));
        $allowedAudiens = explode(',', config('keycloak.aud'));

        $jwksResponse = Cache::remember('keycloak-certificate', config('keycloak.certificate.ttl'), function () {
            $getKeycloakCertificateUrl = config('keycloak.base_url') . '/auth/realms/' . config('keycloak.realm') . '/protocol/openid-connect/certs';
            return file_get_contents($getKeycloakCertificateUrl);
        });

        $jwks = json_decode($jwksResponse, true);

        $result = JWT::decode($token, JWK::parseKeySet($jwks));

        if (!in_array($result->iss, $allowedIssuers)) {
            throw new Exception("Unknown Issuer: " . $result->iss);
        }

        $isAudienceValid = true;

        // Non Audience Token
        if (empty($result->aud) != !(bool)config('keycloak.aud')) {
            $isAudienceValid = false;
        } else {
            $isAudienceValid = IsAudienceValid($allowedAudiens, $result->aud);
        }

        if (!$isAudienceValid) {
            throw new Exception("Unknown Audiences");
        }

        return $result;
    } catch (\Throwable $th) {
        throw new Exception($th->getMessage());
    }
}
