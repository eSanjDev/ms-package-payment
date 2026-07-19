<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Service Base URL
    |--------------------------------------------------------------------------
    | The base URL of the Esanj Payment microservice (without a trailing slash).
    */
    'base_url' => env('PAYMENT_SERVICE_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Client Credentials
    |--------------------------------------------------------------------------
    | The merchant credentials used to obtain an access token. The token itself
    | is issued by the OAuth server configured for the auth-bridge package
    | (esanj.auth_bridge.base_url) via the client_credentials grant, and is then
    | sent as a Bearer token to the Payment service.
    */
    'client_id' => env('PAYMENT_CLIENT_ID'),
    'client_secret' => env('PAYMENT_CLIENT_SECRET'),
    'scope' => env('PAYMENT_SCOPE', '*'),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    | attempts : total number of attempts (1 = no retry).
    | sleep_ms : milliseconds to wait between retries.
    */
    'retry' => [
        'attempts' => (int) env('PAYMENT_RETRY_ATTEMPTS', 3),
        'sleep_ms' => (int) env('PAYMENT_RETRY_SLEEP_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('PAYMENT_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | The log channel used by the client. null = the application default channel.
    */
    'logging' => [
        'channel' => env('PAYMENT_LOG_CHANNEL'),
    ],
];