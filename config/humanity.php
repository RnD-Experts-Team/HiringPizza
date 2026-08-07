<?php

return [
    /*
     |--------------------------------------------------------------------------
     | TCP Humanity — employee push
     |--------------------------------------------------------------------------
     | HiringPizza is the ONLY writer of employees into Humanity. OperationsPizza
     | asks over NATS when it meets an unlinked employee; it never writes staff
     | itself, because two writers to one external system is how duplicate
     | people get created.
     */
    'driver' => env('HUMANITY_DRIVER', 'fake'),

    // No default: Humanity production is live with real users, and pointing a
    // dev box at it by forgetting a variable is not a recoverable mistake.
    'environment' => env('HUMANITY_ENV'),

    /*
     | Master switch for staff writes. MUST stay false until
     | `humanity:backfill-employee-ids` has matched the existing live roster —
     | every current employee was created by hand in Humanity and carries no
     | eid, so an unguarded upsert would duplicate all of them.
     */
    'writes_enabled' => (bool) env('HUMANITY_WRITES_ENABLED', false),

    'base_url' => env('HUMANITY_BASE_URL', 'https://www.humanity.com/api/v2'),
    'token_url' => env('HUMANITY_TOKEN_URL', 'https://www.humanity.com/oauth2/token.php'),

    /*
     | v2 supports only the `password` and `refresh_token` grants — there is no
     | client_credentials — so this authenticates AS a real user whose ROLE is
     | the entire permission model (v2 returns scope: null).
     | POST /employees needs level 4 (Scheduler) or better.
     */
    'client_id' => env('HUMANITY_CLIENT_ID'),
    'client_secret' => env('HUMANITY_CLIENT_SECRET'),
    'username' => env('HUMANITY_USERNAME'),
    'password' => env('HUMANITY_PASSWORD'),
    'redirect_uri' => env('HUMANITY_REDIRECT_URI'),

    // Kept short deliberately: the push runs inside the employee-create
    // transaction, so this is how long a DB transaction can be held open.
    'timeout' => (int) env('HUMANITY_TIMEOUT', 5),
    'retries' => (int) env('HUMANITY_RETRIES', 1),
    'retry_ms' => (int) env('HUMANITY_RETRY_MS', 200),

    // Humanity's employee `group` (role). 5 = Employee.
    'default_group' => (int) env('HUMANITY_DEFAULT_GROUP', 5),

    // Whether Humanity emails the new hire an activation link.
    'send_activation' => (bool) env('HUMANITY_SEND_ACTIVATION', false),

    // The id_types label under which the returned Humanity id is stored,
    // alongside the existing Altametrics/Paychecks ids.
    'id_type_label' => env('HUMANITY_ID_TYPE_LABEL', 'Humanity ID'),
];
