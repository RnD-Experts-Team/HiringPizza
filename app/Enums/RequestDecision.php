<?php

namespace App\Enums;

enum RequestDecision: string
{
    case Rejected = 'rejected';
    case Completed = 'completed';
}
