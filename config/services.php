<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | pizzasys auth server
    |--------------------------------------------------------------------------
    | AuthTokenStoreScopeMiddleware POSTs every request's bearer token here.
    | `service_name` is ONE string doing three jobs in pizzasys and they must
    | all agree or every request 403s: the `service` field of the verify
    | request, `service_clients.name`, and `auth_rules.service`. There is
    | deliberately NO default — a wrong placeholder ("my-service") fails
    | silently as a 403 on every request, while a blank fails loudly as a 500
    | naming this config.
    */
    'auth_server' => [
        'base_url' => env('AUTH_SERVER_BASE_URL', 'http://auth-service.local'),
        'verify_path' => env('AUTH_SERVER_VERIFY_PATH', '/api/v1/auth/token/verify'),
        'service_name' => env('AUTH_SERVER_SERVICE_NAME'),
        'call_token' => env('AUTH_SERVER_CALL_TOKEN', ''),
        'timeout' => 3,
        'retries' => 1,
        'retry_ms' => 100,
    ],

    // Shared-secret header for the export routes (routes/api.php). Blank means
    // those four routes return 500 — set it or they cannot be used at all.
    'x_secret_key' => env('X_SECRET_KEY'),
];
