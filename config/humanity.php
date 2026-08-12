<?php

return [
    /*
     |--------------------------------------------------------------------------
     | TCP's native TimeClock Plus <-> Humanity connector
     |--------------------------------------------------------------------------
     | TCP ships its own connector between TimeClock Plus (Manager+) and
     | Humanity. It runs every 5 minutes, and TimeClock Plus is the system of
     | record for employee profiles, leave, skills, locations/departments and
     | job codes; Humanity owns only the schedule.
     |
     | When it is active, writing employees into Humanity from here makes us a
     | SECOND writer competing with TCP on a 5-minute loop — the exact
     | duplicate-people failure the `eid` scheme exists to prevent, except the
     | other writer is TCP's and cannot be coordinated with.
     |
     | Worse: TCP's connector matches employees between the two systems using an
     | external id field, and Humanity's standard one is `eid` — precisely what
     | HumanityEmployeeMapper and humanity:backfill-employee-ids stamp with OUR
     | employee id. Overwriting it would break TCP's production sync.
     |
     | Defaults to TRUE because true is the SAFE value: it blocks the employee
     | push and the backfill. Only set it false once you have confirmed the
     | connector is genuinely off for this account.
     |
     | The correct topology while it is on:
     |     HiringPizza -> TCP Manager+ -> (TCP connector) -> Humanity
     */
    'tcp_connector_active' => (bool) env('TCP_CONNECTOR_ACTIVE', true),

    /*
     |--------------------------------------------------------------------------
     | TCP Humanity — employee push
     |--------------------------------------------------------------------------
     | Only reachable when tcp_connector_active is false. See above.
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
