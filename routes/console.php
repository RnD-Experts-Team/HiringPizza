<?php


Schedule::command('outbox:publish-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Opens a shirt milestone for each completed month of tenure. Idempotent:
// re-running it creates nothing new, so a missed night self-heals.
Schedule::command('shirts:generate-milestones')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// Mirrors TCP's job-code catalog into tcp_job_codes, which the TCP employee
// push matches positions against. Without this a job code added in TCP is
// invisible here until someone runs it by hand, and pushes for that position
// fail with "No TCP job code matches". One TCP call a day.
Schedule::command('tcp:sync-job-codes')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->skip(fn () => config('tcp.driver') !== 'http');
