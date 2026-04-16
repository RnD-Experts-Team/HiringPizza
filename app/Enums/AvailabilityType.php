<?php

namespace App\Enums;

enum AvailabilityType: string
{
    case WeekDay = 'weekday';
    case WeekEnd = 'weekend';
    case OpenAvailability = 'open_availability';
}
