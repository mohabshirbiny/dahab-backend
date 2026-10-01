<?php

namespace App\Enums;

/**
 * `order_state` (schema §1). Spec 011 creates an order at acceptance
 * (`awaiting_delivery`) and lets staff cancel it (`cancelled_staff`, final);
 * the rest belong to the orders module. Moves follow `order_transition`
 * (trg_order_transition).
 */
enum OrderState: string
{
    case AWAITING_DELIVERY = 'awaiting_delivery';
    case AT_INSPECTION = 'at_inspection';
    case INSPECTION_PASSED = 'inspection_passed';
    case WEIGHT_ADJUST_PENDING = 'weight_adjust_pending';
    case AWAITING_BALANCE = 'awaiting_balance';
    case READY_TO_COLLECT = 'ready_to_collect';
    case COMPLETED = 'completed';
    case CANCELLED_SELLER = 'cancelled_seller';
    case CANCELLED_BUYER_NOPAY = 'cancelled_buyer_nopay';
    case CANCELLED_INSPECTION = 'cancelled_inspection';
    case DISPUTED = 'disputed';
    case CANCELLED_STAFF = 'cancelled_staff';

    public function label(): string
    {
        return match ($this) {
            self::AWAITING_DELIVERY => 'Waiting for the seller to deliver',
            self::AT_INSPECTION => 'At inspection',
            self::INSPECTION_PASSED => 'Inspection passed',
            self::WEIGHT_ADJUST_PENDING => 'Weight adjustment pending',
            self::AWAITING_BALANCE => 'Waiting for the balance',
            self::READY_TO_COLLECT => 'Ready to collect',
            self::COMPLETED => 'Completed',
            self::CANCELLED_SELLER => 'Cancelled by the seller',
            self::CANCELLED_BUYER_NOPAY => 'Cancelled, the buyer did not pay',
            self::CANCELLED_INSPECTION => 'Cancelled at inspection',
            self::DISPUTED => 'Disputed',
            self::CANCELLED_STAFF => 'Cancelled by Dahab',
        };
    }
}
