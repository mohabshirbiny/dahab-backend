<?php

namespace App\Enums;

/** Why an advance was written off (research R14). */
enum WriteOffReason: string
{
    case BUYER_DID_NOT_PAY = 'buyer_did_not_pay';
    case CANCELLED_AT_INSPECTION = 'cancelled_at_inspection';
    case CANCELLED_BY_STAFF = 'cancelled_by_staff';
    case DISPUTE_AGAINST_SALE = 'dispute_against_sale';
}
