<?php

namespace App\Http\Resources\Staff;

use App\Enums\WithdrawalState;
use App\Http\Resources\Pricing\StaffRef;
use App\Models\Withdrawal;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffWithdrawal',
    description: 'A withdrawal for staff (spec 013 FR-012). Holders of withdrawal.release see the full account number and the `can` actions; wallet.view alone reads it masked with no actions.',
    required: ['id', 'number', 'amount', 'state', 'requested_at', 'customer', 'account', 'signals', 'hold', 'can'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'WD-12'),
        new OA\Property(property: 'amount', type: 'string', example: '42000.0000'),
        new OA\Property(property: 'state', type: 'string', enum: ['requested', 'under_review', 'released', 'rejected', 'cancelled']),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'review_started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reviewer', nullable: true, properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'full_name', type: 'string')], type: 'object'),
        new OA\Property(property: 'hold', nullable: true, description: 'The hold record; on_hold is true only while under review', properties: [
            new OA\Property(property: 'on_hold', type: 'boolean'),
            new OA\Property(property: 'reason', type: 'string', enum: ['name_mismatch', 'account_changed_recently', 'identity_pending', 'money_in_straight_out', 'other']),
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'note', type: 'string'),
            new OA\Property(property: 'by', type: 'string', nullable: true),
            new OA\Property(property: 'at', type: 'string', format: 'date-time'),
        ], type: 'object'),
        new OA\Property(property: 'customer', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'display_ref', type: 'string'),
            new OA\Property(property: 'full_name', type: 'string', nullable: true),
            new OA\Property(property: 'available', type: 'string', example: '56760.0000', description: 'Available balance now'),
        ], type: 'object'),
        new OA\Property(property: 'account', ref: '#/components/schemas/StaffPayoutAccount'),
        new OA\Property(property: 'signals', properties: [
            new OA\Property(property: 'identity_verified', type: 'boolean'),
            new OA\Property(property: 'suspended', type: 'boolean'),
            new OA\Property(property: 'account_added_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'first_payout_to_account', type: 'boolean'),
            new OA\Property(property: 'payouts_to_account', type: 'integer'),
            new OA\Property(property: 'in_use_changed_at', type: 'string', format: 'date-time', nullable: true, description: 'The account in use changed within the last 30 days'),
            new OA\Property(property: 'completed_sales', type: 'integer'),
            new OA\Property(property: 'topped_up_never_traded', type: 'boolean'),
        ], type: 'object'),
        new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'bank_txn_number', type: 'string', nullable: true),
        new OA\Property(property: 'transfer_reference', type: 'string', nullable: true),
        new OA\Property(property: 'value_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'rejection', nullable: true, properties: [new OA\Property(property: 'reason', type: 'string'), new OA\Property(property: 'note', type: 'string')], type: 'object'),
        new OA\Property(property: 'cancelled_by_change', type: 'boolean'),
        new OA\Property(property: 'can', properties: [
            new OA\Property(property: 'review', type: 'boolean'),
            new OA\Property(property: 'hold', type: 'boolean'),
            new OA\Property(property: 'unhold', type: 'boolean'),
            new OA\Property(property: 'release', type: 'boolean'),
            new OA\Property(property: 'reject', type: 'boolean'),
        ], type: 'object'),
    ],
)]
final class StaffWithdrawalResource
{
    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    public static function item(Withdrawal $w, array $signals, string $available, bool $canAct, bool $fullNumber): array
    {
        $a = $w->account;
        $state = $w->state;

        return [
            'id' => $w->withdrawal_id,
            'number' => $w->number(),
            'amount' => bcadd((string) $w->amount, '0', 4),
            'state' => $state->value,
            'requested_at' => $w->requested_at->toIso8601String(),
            'review_started_at' => $w->review_started_at?->toIso8601String(),
            'reviewer' => StaffRef::of($w->reviewer),
            'hold' => $w->held_at === null ? null : [
                'on_hold' => $w->isOnHold(),
                'reason' => $w->hold_reason?->value,
                'message' => $w->hold_message,
                'note' => $w->hold_note,
                'by' => $w->holder?->full_name,
                'at' => $w->held_at->toIso8601String(),
            ],
            'customer' => [
                'id' => $w->customer_id,
                'display_ref' => $w->customer?->display_ref,
                'full_name' => $w->customer?->full_name,
                'available' => $available,
            ],
            'account' => $a === null ? null : [
                'id' => $a->payout_account_id,
                'bank_name' => $a->bank_name,
                'account_name' => $a->account_name,
                'number' => $fullNumber ? $a->account_number_or_iban : null,
                'number_masked' => $a->masked(),
                'kind' => $a->kind(),
                'state' => $a->state->value,
                'in_use' => $a->is_in_use,
                'added_at' => $a->created_at?->toIso8601String(),
                'checked' => $a->name_checked_at === null ? null : ['by' => $a->checkedBy?->full_name, 'at' => $a->name_checked_at->toIso8601String()],
                'refusal' => null,
            ],
            'signals' => $signals,
            'released_at' => $w->released_at?->toIso8601String(),
            'bank_txn_number' => $w->bank_txn_number,
            'transfer_reference' => $w->transfer_reference,
            'value_date' => $w->value_date?->toDateString(),
            'rejection' => $w->rejection_reason === null ? null : ['reason' => $w->rejection_reason->value, 'note' => $w->rejection_note],
            'cancelled_by_change' => $w->cancelled_by_change,
            'can' => [
                'review' => $canAct && $state === WithdrawalState::REQUESTED,
                'hold' => $canAct && $state === WithdrawalState::UNDER_REVIEW && $w->held_at === null,
                'unhold' => $canAct && $w->isOnHold(),
                'release' => $canAct && $state === WithdrawalState::UNDER_REVIEW && $w->held_at === null,
                'reject' => $canAct && $state->canMoveTo(WithdrawalState::REJECTED),
            ],
        ];
    }
}
