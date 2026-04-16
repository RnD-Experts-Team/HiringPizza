<?php

namespace App\Enums;

enum TerminationReason: string
{
    case PerformanceIssues = 'performance_issues';
    case PolicyViolationMisconduct = 'policy_violation_misconduct';
    case AttendanceIssues = 'attendance_issues';
    case NoCallNoShowJobAbandonment = 'no_call_no_show_more_than_2_times_job_abandonment';
    case EndOfTrialPeriod = 'end_of_trial_period';
    case ReachTheLimitsOfCAPsNeeded = 'reach_the_limits_of_caps_needed';
    case Other = 'other';
}
