<?php

return [

    'vendor_id' => env('PADDLE_VENDOR_ID'),
    'api_key' => env('PADDLE_API_KEY'),

    // Put the public key directly here (multi-line supported in PHP arrays)
    'public_key' => <<<EOD
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...
...rest of your Paddle key...
-----END PUBLIC KEY-----
EOD,

    'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
    'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
    'success_url' => env('PADDLE_SUCCESS_URL'),
    'cancel_url' => env('PADDLE_CANCEL_URL'),
];
