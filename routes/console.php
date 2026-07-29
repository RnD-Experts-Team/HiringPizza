<?php


Schedule::command('outbox:publish-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();