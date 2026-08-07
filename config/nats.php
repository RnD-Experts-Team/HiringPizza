<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode
    ? 'auth.testing.v1.>'
    : 'auth.v1.>';

$hiringSubject = $devMode
    ? 'hiring.testing.v1.>'
    : 'hiring.v1.>';

$notificationsSubject = $devMode
    ? 'notifications.testing.v1.>'
    : 'notifications.v1.>';

$operationsSubject = $devMode
    ? 'operations.testing.v1.>'
    : 'operations.v1.>';

return [
    'dev_mode' => $devMode,
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),



    'publishers' => [
        [
            'name' => $devMode
                ? env('NATS_HIRING_STREAM', 'HIRING_TESTING_EVENTS')
                : env('NATS_HIRING_STREAM', 'HIRING_EVENTS'),
            'subjects' => [$hiringSubject],
        ],
        [
            'name' => $devMode
                ? env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_TESTING_EVENTS')
                : env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_EVENTS'),
            'subjects' => [$notificationsSubject],
        ],
    ],
    /**
     * Add streams here as new projects appear.
     * Each stream gets its own durable pull consumer.
     */
    'streams' => [
        [
            'name' => $devMode ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS') : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable' => $devMode ? env('NATS_AUTH_DURABLE', 'HIRING_AUTH_TESTING_CONSUMER') : env('NATS_AUTH_DURABLE', 'HIRING_AUTH_CONSUMER'),
            'filter_subject' => $authSubject, // match your stream subjects
        ],

        /**
         * OperationsPizza asks us to push an employee into Humanity when it
         * meets one with no link. We own employee writes, so it cannot do this
         * itself — see EmployeeHumanitySyncRequestedHandler.
         */
        [
            'name' => $devMode
                ? env('NATS_OPERATIONS_STREAM', 'OPERATIONS_TESTING_EVENTS')
                : env('NATS_OPERATIONS_STREAM', 'OPERATIONS_EVENTS'),
            'durable' => $devMode
                ? env('NATS_OPERATIONS_DURABLE', 'HIRING_OPERATIONS_TESTING_CONSUMER')
                : env('NATS_OPERATIONS_DURABLE', 'HIRING_OPERATIONS_CONSUMER'),
            'filter_subject' => $operationsSubject,
        ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
