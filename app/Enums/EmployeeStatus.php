<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Hired = 'hired';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case Rehired = 'rehired';
    case OJE = 'OJE';
}