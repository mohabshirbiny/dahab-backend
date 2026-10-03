<?php

namespace App\Http\Resources\Customer;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerWithdrawal',
    description: 'One of the customer\'s own withdrawals (spec 013). Never the reviewer, a staff note or the bank transaction number.',
    required: ['id', 'number', 'amount', 'state', 'on_hold', 'hold_message', 'account', 'requested_at', 'released_at', 'value_date', 'rejection_reason', 'cancelled_by_change', 'can_cancel'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'WD-12'),
        new OA\Property(property: 'amount', type: 'string', example: '42000.0000'),
        new OA\Property(property: 'state', type: 'string', enum: ['requested', 'under_review', 'released', 'rejected', 'cancelled']),
        new OA\Property(property: 'on_hold', type: 'boolean', description: 'Under review and held by staff'),
        new OA\Property(property: 'hold_message', type: 'string', nullable: true, description: 'What staff asked the customer, while on hold'),
        new OA\Property(property: 'account', properties: [
            new OA\Property(property: 'bank_name', type: 'string'),
            new OA\Property(property: 'number_masked', type: 'string', example: '•••• 4417'),
        ], type: 'object'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'value_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'rejection_reason', type: 'string', nullable: true, enum: ['account_not_in_name', 'money_in_straight_out', 'identity_unconfirmed', 'customer_request', 'other']),
        new OA\Property(property: 'cancelled_by_change', type: 'boolean', description: 'Cancelled because the account in use changed'),
        new OA\Property(property: 'can_cancel', type: 'boolean'),
    ],
)]
class WithdrawalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Withdrawal $w */
        $w = $this->resource;
        $onHold = $w->isOnHold();

        return [
            'id' => $w->withdrawal_id,
            'number' => $w->number(),
            'amount' => bcadd((string) $w->amount, '0', 4),
            'state' => $w->state->value,
            'on_hold' => $onHold,
            'hold_message' => $onHold ? $w->hold_message : null,
            'account' => [
                'bank_name' => $w->account?->bank_name,
                'number_masked' => $w->account?->masked(),
            ],
            'requested_at' => $w->requested_at->toIso8601String(),
            'released_at' => $w->released_at?->toIso8601String(),
            'value_date' => $w->value_date?->toDateString(),
            'rejection_reason' => $w->rejection_reason?->value,
            'cancelled_by_change' => $w->cancelled_by_change,
            'can_cancel' => $w->state->isOpen(),
        ];
    }
}
