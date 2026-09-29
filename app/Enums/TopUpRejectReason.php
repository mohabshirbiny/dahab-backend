<?php

namespace App\Enums;

/**
 * Why staff rejected a transfer notice (`topup_reject_reason`, spec 009
 * FR-025a). The customer is told the reason, never the staff note.
 */
enum TopUpRejectReason: string
{
    case MONEY_NOT_RECEIVED = 'money_not_received';
    case DUPLICATE_NOTICE = 'duplicate_notice';
    case SENDER_NOT_ACCEPTED = 'sender_not_accepted';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MONEY_NOT_RECEIVED => 'We did not receive this transfer',
            self::DUPLICATE_NOTICE => 'This transfer was already reported in another notice',
            self::SENDER_NOT_ACCEPTED => 'We could not accept a transfer from this sender',
            self::OTHER => 'We could not match this transfer',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::MONEY_NOT_RECEIVED => 'لم يصلنا هذا التحويل',
            self::DUPLICATE_NOTICE => 'تم الإبلاغ عن هذا التحويل في إشعار آخر',
            self::SENDER_NOT_ACCEPTED => 'لم نتمكن من قبول تحويل من هذا المرسل',
            self::OTHER => 'لم نتمكن من مطابقة هذا التحويل',
        };
    }
}
