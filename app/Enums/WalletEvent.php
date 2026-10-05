<?php

namespace App\Enums;

/**
 * Something Dahab did to a customer's wallet outside an order (spec 015
 * research R14): compensation from the Compensation page, or a staff
 * correction; since spec 016 a credit note on a tax invoice. Told by SMS +
 * email after commit (WalletNotification).
 */
enum WalletEvent: string
{
    case COMPENSATION_PAID = 'compensation_paid';
    case WALLET_ADJUSTED = 'wallet_adjusted';
    case CREDIT_NOTE_ISSUED = 'credit_note_issued';
}
