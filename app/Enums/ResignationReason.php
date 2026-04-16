<?php

namespace App\Enums;

enum ResignationReason: string
{
    case FoundAnotherJob = 'found_another_job';
    case SchoolScheduleConflict = 'school_schedule_conflict';
    case Relocation = 'relocation';
    case PersonalReasons = 'personal_reasons';
    case HealthFamilyReasons = 'health_family_reasons';
    case CognitoForm = 'cognito_form';
    case Other = 'other';
}
