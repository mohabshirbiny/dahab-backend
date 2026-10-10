<?php

namespace App\Enums;

/**
 * What an order notification is about (spec 012 FR-025, research R20). Each is
 * sent by SMS, plus email when the customer has one, after the change commits.
 */
enum OrderEvent: string
{
    case RECEIVED = 'received';
    case SELLER_CANCELLED = 'seller_cancelled';
    case DEADLINE_MISSED = 'deadline_missed';
    case BRANCH_CHANGED = 'branch_changed';
    case DEADLINE_EXTENDED = 'deadline_extended';
    case RESULT_PASSED = 'result_passed';
    case RESULT_ADJUST = 'result_adjust';
    case RESULT_BUYER_DECIDING = 'result_buyer_deciding';
    case RESULT_REGRADE_PENDING = 'result_regrade_pending';
    case PRICE_PROPOSED = 'price_proposed';
    case RESULT_CANCELLED = 'result_cancelled';
    case DECISION_ACCEPTED = 'decision_accepted';
    case DECISION_DECLINED = 'decision_declined';
    case DECISION_EXPIRED = 'decision_expired';
    case PAID = 'paid';
    case COLLECTION_CODE = 'collection_code';
    case FORFEITED = 'forfeited';
    case RETURN_WAITING = 'return_waiting';
    case RETURN_WINDOW_PASSED = 'return_window_passed';
    case COLLECTED = 'collected';
    case COLLECTION_WINDOW_PASSED = 'collection_window_passed';
    case REACH_REMINDER = 'reach_reminder';
    case BALANCE_REMINDER = 'balance_reminder';
    // Spec 014.
    case DISPUTE_OPENED = 'dispute_opened';
    case DISPUTE_RESOLVED = 'dispute_resolved';
    case DISPUTE_RESUMED = 'dispute_resumed';
    case DISPUTE_CANCELLED = 'dispute_cancelled';
    case COMPENSATION_PAID = 'compensation_paid';
    case EXTENSION_REFUSED = 'extension_refused';
    case PROXY_NAMED = 'proxy_named';
    // Spec 018: the buyer's free relist went live.
    case FREE_RELISTED = 'free_relisted';
}
