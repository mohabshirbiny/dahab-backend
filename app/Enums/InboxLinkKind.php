<?php

namespace App\Enums;

/**
 * What an inbox item opens in the app (spec 017 FR-031). `wallet`, `account`
 * and `none` carry no id; every other kind names one row.
 */
enum InboxLinkKind: string
{
    case ORDER = 'order';
    case LISTING = 'listing';
    case BUY_REQUEST = 'buy_request';
    case WALLET = 'wallet';
    case WITHDRAWAL = 'withdrawal';
    case PAYOUT_ACCOUNT = 'payout_account';
    case TOPUP = 'topup';
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
    case DISPUTE = 'dispute';
    case ACCOUNT = 'account';
    case NONE = 'none';

    public function needsId(): bool
    {
        return ! in_array($this, [self::WALLET, self::ACCOUNT, self::NONE], true);
    }
}
