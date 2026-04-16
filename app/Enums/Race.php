<?php

namespace App\Enums;

enum Race: string
{
    case Caucasian = 'Caucasian';
    case AfricanAmerican = 'African American';
    case Hispanic = 'Hispanic';
    case Asian = 'Asian';
    case NativeAmerican = 'Native American';
    case Other = 'Other';
}