<?php

namespace App\Enums;

enum StaffRole: string
{
    case CEO = 'ceo';
    case COO = 'coo';
    case FINANCE = 'finance';
    case OPERATIONS = 'operations';
    case VERIFICATION = 'verification';
    case IGI_BRANCH = 'igi_branch';

    public function isMfaRequired(): bool
    {
        return in_array($this->value, config('dahab-auth.mfa_required_roles'), true);
    }

    public function isFounder(): bool
    {
        return in_array($this->value, config('dahab-auth.founder_roles'), true);
    }
}
