<?php

namespace App\Enums;

enum ContactType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case EmergencyContact = 'emergency_contact';
}