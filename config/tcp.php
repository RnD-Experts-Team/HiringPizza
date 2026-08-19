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
    // transaction open. POSTs are never TRANSPORT-retried (no idempotency key
    // — a timed-out request may have landed, so blindly resending it here
    // risks a duplicate person). TcpEmployeeSyncService's employeeId-conflict
    // retry is a different, deliberate thing: it only fires on a definitive
    // synchronous rejection (TCP said no, nothing landed), never on a timeout.
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

    /*
     | Whether WE assign employeeId on create, vs leaving it out and letting
     | TCP auto-generate the next available id (its own documented behaviour
     | for a null value). The UI requiring manual entry when adding an
     | employee by hand doesn't prove the API does — that's still unconfirmed,
     | so this is a real toggle, not a settled decision. If false works
     | reliably, the offset scheme below can go away entirely.
     */
    'assign_employee_id' => (bool) env('TCP_ASSIGN_EMPLOYEE_ID', true),

    /*
     | Only used when assign_employee_id is true. The live roster's native ids
     | are low integers ("5896"-style), so ours are built as this offset + our
     | own id, landing far outside that range instead of colliding with a real
     | person. TcpEmployeeMapper::candidateEmployeeId().
     */
    'employee_id_offset' => (int) env('TCP_EMPLOYEE_ID_OFFSET', 9000000),

    /*
     | Employee `roleId` is a plain string. On this account it is not a
     | permission role at all — it is a US state postal code, confirmed
     | directly from the live account (not TCP's general docs, which barely
     | describe the field). Only these values are legal to send; anything
     | else is TCP silently rejecting or ignoring the field, which is exactly
     | how the "role never got set" report happened in the first place.
     | TcpStoreRole (tcp:sync-role-map / tcp:map-role) maps a store to one of
     | these; TcpEmployeeMapper refuses to send a value outside this list.
     */
    'valid_role_ids' => array_filter(array_map('trim', explode(',', env(
        'TCP_VALID_ROLE_IDS',
        'AL,CO,IA,IN,KY,MA,MI,OH,SD,WY'
    )))),
];
