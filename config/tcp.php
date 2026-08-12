<?php

return [
    /*
     |--------------------------------------------------------------------------
     | TCP Manager+ (TimeClock Plus) — employee push
     |--------------------------------------------------------------------------
     | TCP is the system of record for employee profiles, leave, skills,
     | locations/departments and job codes. Its own connector then syncs those
     | into Humanity every 5 minutes; Humanity owns only the schedule.
     |
     | So HiringPizza pushes employees HERE, not into Humanity:
     |
     |     HiringPizza -> TCP Manager+ -> (TCP connector) -> Humanity
     |
     | Writing to Humanity directly would make us a second writer competing
     | with TCP on a 5-minute loop — see config/humanity.php.
     |
     | Docs: https://timeclock-plus.readme.io
     */
    'driver' => env('TCP_DRIVER', 'fake'),

    // No default: production holds the live roster.
    'environment' => env('TCP_ENV'),

    'writes_enabled' => (bool) env('TCP_WRITES_ENABLED', false),

    'base_url' => env('TCP_BASE_URL', 'https://api.tcplusondemand.com/v1'),
    'token_url' => env('TCP_TOKEN_URL', 'https://auth.api.tcplusondemand.com/oauth2/token'),

    /*
     | client_credentials — a genuine machine-to-machine grant. Notably better
     | than Humanity's v2, which only offers the `password` grant and therefore
     | needs a service-account login that can expire or gain 2FA.
     */
    'client_id' => env('TCP_CLIENT_ID'),
    'client_secret' => env('TCP_CLIENT_SECRET'),
    'scope' => env('TCP_SCOPE', 'tcp-openapi/tcpopenapi.write tcp-openapi/tcpopenapi.read'),

    'api_key' => env('TCP_API_KEY'),
    'company_id' => env('TCP_COMPANY_ID', '1'),

    // Short: the push runs inside the employee-create transaction, so this is
    // how long a DB transaction can be held open.
    'timeout' => (int) env('TCP_TIMEOUT', 5),
    'retries' => (int) env('TCP_RETRIES', 1),
    'retry_ms' => (int) env('TCP_RETRY_MS', 200),

    /*
     | TCP allows 60/minute and 2500/24h. Employee writes are a small share of
     | that, but a bulk CSV import would blow straight through it — which is why
     | EmployeeCsvImportService is deliberately not wired to push.
     */
    'rate_limit' => [
        'per_minute' => (int) env('TCP_RATE_PER_MINUTE', 60),
        'per_day' => (int) env('TCP_RATE_PER_DAY', 2500),
    ],

    /*
     | The id_types label under which TCP's employeeId is stored, alongside the
     | existing Altametrics/Paychecks/Humanity ids. It rides to OperationsPizza
     | for free, because the employee snapshot already serialises ids[].id_type.
     */
    'id_type_label' => env('TCP_ID_TYPE_LABEL', 'TCP ID'),

    /*
     | TCP's `employeeId` is CLIENT-SUPPLIED on create. Setting it to our own
     | employee id is what makes the push idempotent and keeps one identity all
     | the way through to Humanity. Turn this off only if TCP's id space is
     | already occupied by values we don't control.
     */
    'use_our_employee_id' => (bool) env('TCP_USE_OUR_EMPLOYEE_ID', true),

    // Fallback job code for a new hire with no mapped position; TCP requires
    // a defaultJobCode on create.
    'default_job_code' => env('TCP_DEFAULT_JOB_CODE'),
];
