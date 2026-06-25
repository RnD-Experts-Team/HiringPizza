<?php

namespace App\Enums;

enum MilestoneGiftMilestone: string
{
    case ThirtyDays = '30_days';
    case NinetyDays = '90_days';
    case SixMonths = '6_months';
    case OneYear = '1_year';
    case TwoYears = '2_years';
    case Other = 'other';
}
