<?php

namespace App\Enums;

enum MilestoneGiftFinalStatusType: string
{
    case DeliveredToEmployee = 'delivered_to_employee';
    case SentToStoreAwaitingPickup = 'sent_to_store_awaiting_pickup';
    case NotDeliveredNoLongerWithCompany = 'not_delivered_no_longer_with_company';
    case NotDeliveredOtherReason = 'not_delivered_other_reason';
}
