<?php

namespace App\Enums;

enum SuspendedReason: string
{
    case FRAUD_SUSPECTED = 'fraud_suspected';
    case POLICY_VIOLATION = 'policy_violation';
    case KYC_FAILED = 'kyc_failed';
    case STAFF_REQUEST = 'staff_request';
    case OTHER = 'other';
}
