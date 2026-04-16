<?php

namespace App\Enums;

enum Religion: string
{
    case Christianity = 'Christianity';
    case Islam = 'Islam';
    case Judaism = 'Judaism';
    case Buddhism = 'Buddhism';
    case Hinduism = 'Hinduism';
    case Other = 'Other';
}