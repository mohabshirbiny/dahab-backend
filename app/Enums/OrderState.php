<?php

namespace App\Enums;

/**
 * `order_state` (schema §1). Spec 011 creates an order at acceptance
 * (`awaiting_delivery`) and lets staff cancel it (`cancelled_staff`, final);
 * spec 012 moves it through delivery, inspection, payment and collection.
 * Moves follow `order_transition` (trg_order_transition, SQLSTATE DH006).
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

    /** No move leaves these (`disputed` is never reached in spec 012). */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED, self::CANCELLED_SELLER, self::CANCELLED_BUYER_NOPAY,
            self::CANCELLED_INSPECTION, self::CANCELLED_STAFF,
        ], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    public function isCancelled(): bool
    {
        return $this->isFinal() && $this !== self::COMPLETED;
    }

    /** The staff Orders page group (spec 012 research R18). */
    public function group(): string
    {
        return match ($this) {
            self::AWAITING_DELIVERY => 'waiting_seller',
            self::AT_INSPECTION, self::INSPECTION_PASSED => 'at_igi',
            self::WEIGHT_ADJUST_PENDING => 'needs_decision',
            self::AWAITING_BALANCE => 'waiting_balance',
            self::READY_TO_COLLECT, self::DISPUTED => 'ready_to_collect',
            default => 'closed',
        };
    }

    /** What the customer's order card shows (spec 012 research R19). */
    public function customerStage(): string
    {
        return match ($this) {
            self::AWAITING_DELIVERY => 'bring_piece',
            self::AT_INSPECTION, self::INSPECTION_PASSED, self::DISPUTED => 'at_igi',
            self::WEIGHT_ADJUST_PENDING => 'decide',
            self::AWAITING_BALANCE => 'pay',
            self::READY_TO_COLLECT => 'collect',
            self::COMPLETED => 'done',
            default => 'cancelled',
        };
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
