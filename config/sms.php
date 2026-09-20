<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default SMS driver
    |--------------------------------------------------------------------------
    | `log` writes the message to the application log and needs no credentials,
    | so it is the safe default for local/testing and for any environment whose
    | provider has not been wired yet. `http` talks to a real provider.
    */

    'default' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Sender ID
    |--------------------------------------------------------------------------
    | The alphanumeric sender shown on the handset. Most providers require this
    | to be pre-registered with them.
    */

    'from' => env('SMS_SENDER_ID', env('APP_NAME', 'Dahab')),

    'drivers' => [

        'log' => [
            // null = the default logging channel.
            'channel' => env('SMS_LOG_CHANNEL'),
        ],

        /*
        | A deliberately generic JSON-over-HTTP driver. Every provider-specific
        | detail — URL, auth style, payload field names — is configuration, so
        | wiring a concrete provider is an .env change, not a code change.
        |
        | `auth` is one of: bearer | basic | header | query | none
        */
        'http' => [
            'base_url' => env('SMS_BASE_URL'),
            'endpoint' => env('SMS_ENDPOINT', '/messages'),
            'api_key' => env('SMS_API_KEY'),
            'api_secret' => env('SMS_API_SECRET'),
            'auth' => env('SMS_AUTH', 'bearer'),
            'auth_header' => env('SMS_AUTH_HEADER', 'X-API-Key'),
            'auth_query_key' => env('SMS_AUTH_QUERY_KEY', 'api_key'),
            'timeout' => (int) env('SMS_TIMEOUT', 10),
            'retries' => (int) env('SMS_RETRIES', 2),
            'retry_delay_ms' => (int) env('SMS_RETRY_DELAY_MS', 250),

            // Maps our three logical fields onto the provider's request body.
            'fields' => [
                'to' => env('SMS_FIELD_TO', 'to'),
                'message' => env('SMS_FIELD_MESSAGE', 'message'),
                'sender' => env('SMS_FIELD_SENDER', 'sender'),
            ],
        ],

    ],

];
