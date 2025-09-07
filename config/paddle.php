<?php

return [
    'paddle' => [
        'vendor_id' => env('PADDLE_VENDOR_ID'),
        'api_key' => env('PADDLE_API_KEY'),
        'public_key' => '-----BEGIN PUBLIC KEY-----MIIBIjAN...',
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
        'success_url' => env('PADDLE_SUCCESS_URL'),
        'cancel_url' => env('PADDLE_CANCEL_URL'),
    ],
];
