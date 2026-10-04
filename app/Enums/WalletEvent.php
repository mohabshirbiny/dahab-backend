<?php

namespace App\Enums;

/**
 * Something Dahab did to a customer's wallet outside an order (spec 015
 * research R14): compensation from the Compensation page, or a staff
 * correction. Told by SMS + email after commit (WalletNotification).
 */
enum WalletEvent: string
{
    case COMPENSATION_PAID = 'compensation_paid';
    case WALLET_ADJUSTED = 'wallet_adjusted';
}
