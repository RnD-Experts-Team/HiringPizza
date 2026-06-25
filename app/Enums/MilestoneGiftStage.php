<?php

namespace App\Enums;

enum MilestoneGiftStage: string
{
    case Created = 'created';
    case Rating = 'rating';
    case GiftDecision = 'gift_decision';
    case FinalStatus = 'final_status';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
