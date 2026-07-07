<?php

namespace App\Enums;

enum MilestoneGiftMilestone: string
{
    case EightDays = '8_days';
    case OneMonth = '1_month';
    case TwoMonths = '2_months';
    case ThreeMonths = '3_months';
    case FourMonths = '4_months';
    case FiveMonths = '5_months';
    case SixMonths = '6_months';
    case EightMonths = '8_months';
    case OneYear = '1_year';
    case Other = 'other';
}
