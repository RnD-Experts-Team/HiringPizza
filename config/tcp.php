<?php

return [
    /*
     |--------------------------------------------------------------------------
     | TCP Manager+ (TimeClock Plus) — the employee push target
     |--------------------------------------------------------------------------
     | TCP is the system of record for employees, leave and job codes. Its own
     | connector carries them into Humanity every 5 minutes:
     |
     |     HiringPizza -> TCP Manager+ -> (TCP connector) -> Humanity
     |
     | This service never writes Humanity's employee records — that would be a
     | second writer competing with the connector.
     | Docs: https://timeclock-plus.readme.io
     |
     | There is NO TCP sandbox — production is the only environment — so what
     | protects it is driver=fake by default, writes_enabled below, and the
     | EXTERNAL_WRITE_ALLOWED_STORES rollout allowlist (config/external.php).
     */
    'driver' => env('TCP_DRIVER', 'fake'),

    // Master switch for the employee push. The client throws on any write
    // while false; the workflow services skip and keep the local write.
    'writes_enabled' => (bool) env('TCP_WRITES_ENABLED', false),

    // Vendor URLs and scope — fixed by TCP, not deployment configuration.
    'base_url' => 'https://api.tcplusondemand.com/v1',
    'token_url' => 'https://auth.api.tcplusondemand.com/oauth2/token',
    'scope' => 'tcp-openapi/tcpopenapi.write tcp-openapi/tcpopenapi.read',

    // Proper machine-to-machine auth — client_credentials, no user password.
    'client_id' => env('TCP_CLIENT_ID'),
    'client_secret' => env('TCP_CLIENT_SECRET'),
    // Sent on every call alongside the bearer token.
    'api_key' => env('TCP_API_KEY'),
    'company_id' => env('TCP_COMPANY_ID', '1'),

    // Tighter than OperationsPizza's 10s/2 on purpose: this push runs INSIDE
    // the employee-create DB transaction, so a slow TCP response holds a
    // transaction open. POSTs are never retried at all (no idempotency key).
    'timeout' => 5,
    'retries' => 1,
    'retry_ms' => 200,

    /*
     | Optional account-wide fallback job code for a new hire whose position
     | matches none of their store's per-store codes ("Crew Member - 3795-01"
     | style, attributed by the Restaurant Id custom field). TCP requires
     | defaultJobCode on create.
     */
    'default_job_code' => env('TCP_DEFAULT_JOB_CODE'),
];
