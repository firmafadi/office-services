<?php

return [

    'secret' => env('JWT_SECRET'),

    'ttl' => env('JWT_TTL', 5),

    'refresh_ttl' => env('JWT_REFRESH_TTL', 20160),

    'leeway' => env('JWT_LEEWAY', 60)
];
