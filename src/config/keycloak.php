<?php

return [

    'base_url' => env('KEYCLOAK_BASE_URL'),

    'realm' => env('KEYCLOAK_REALM'),

    'iss' => env('KEYCLOAK_ISS'),

    'certificate.ttl' => env('KEYCLOAK_CERTIFICATE_TTL', 600)
];
