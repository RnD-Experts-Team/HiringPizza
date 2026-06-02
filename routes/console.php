<?php


Schedule::command('outbox:publish-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('employees:promote-oje')
    ->daily()
    ->withoutOverlapping();