<?php

namespace App\Http\Resources\Staff;

use App\Enums\TopUpStatus;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\TopUpMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffTopUp',
    description: 'A transfer notice or hand credit as staff see it on Incoming transfers (spec 009): the customer view plus the customer, accounts, staff notes and who acted.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'TOP-128'),
        new OA\Property(property: 'origin', type: 'string', enum: ['notice', 'by_hand']),
        new OA\Property(property: 'method', type: 'string', enum: ['bank_transfer', 'instapay', 'vodafone_cash']),
        new OA\Property(property: 'reference', type: 'string', example: 'DAHAB-004417'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'on_hold', 'credited', 'rejected', 'cancelled']),
        new OA\Property(property: 'claimed_amount', type: 'string', nullable: true),
        new OA\Property(property: 'expected_amount', type: 'string', nullable: true, description: 'Display-only estimate of what reaches Dahab after the provider fee the account showed when the notice was filed (a snapshot: later fee changes never move it); null when there was no fee or the notice is closed. Staff credit what actually arrives'),
        new OA\Property(property: 'notice_fee_percent', type: 'string', nullable: true, example: '1.000', description: 'The provider fee the account showed when the notice was filed (display only; the basis of expected_amount); null for a hand credit or when there was no fee'),
        new OA\Property(property: 'credited_amount', type: 'string', nullable: true),
        new OA\Property(property: 'has_receipt', type: 'boolean'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'customer', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'display_ref', type: 'string'),
            new OA\Property(property: 'full_name', type: 'string', nullable: true),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'notice_account', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'method', type: 'string'), new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'provider_fee_percent', type: 'string', nullable: true, description: 'Current fee on the account, display only (may differ from notice_fee_percent)'),
        ]),
        new OA\Property(property: 'receiving_account', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'method', type: 'string'), new OA\Property(property: 'label', type: 'string'),
        ]),
        new OA\Property(property: 'hold_note', type: 'string', nullable: true),
        new OA\Property(property: 'held_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'held_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reject_reason', type: 'string', nullable: true),
        new OA\Property(property: 'reject_note', type: 'string', nullable: true),
        new OA\Property(property: 'rejected_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'rejected_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'credit_note', type: 'string', nullable: true),
        new OA\Property(property: 'arrival_reference', type: 'string', nullable: true),
        new OA\Property(property: 'credited_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'credited_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ledger_txn_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'allowed_actions', type: 'array', items: new OA\Items(type: 'string', enum: ['match', 'hold', 'unhold', 'reject'])),
    ],
)]
class TopUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TopUp $t */
        $t = $this->resource;
        $staff = fn (?Staff $s) => $s === null ? null : ['id' => $s->staff_id, 'full_name' => $s->full_name];
        $account = fn (?ReceivingAccount $a) => $a === null ? null : ['id' => $a->receiving_account_id, 'method' => $a->method->value, 'label' => $a->label];
        $customer = $t->customer;

        return [
            'id' => $t->topup_id,
            'number' => $t->number(),
            'origin' => $t->origin->value,
            'method' => $t->method->value,
            'reference' => $t->reference,
            'status' => $t->status->value,
            'claimed_amount' => TopUpMoney::format($t->claimed_amount),
            'expected_amount' => TopUpMoney::format($t->expectedAmount()),
            'notice_fee_percent' => $t->notice_fee_percent === null ? null : bcadd((string) $t->notice_fee_percent, '0', 3),
            'credited_amount' => TopUpMoney::format($t->credited_amount),
            'has_receipt' => $t->receipt_ref !== null,
            'submitted_at' => $t->submitted_at->toIso8601String(),
            'customer' => [
                'id' => $customer->customer_id,
                'display_ref' => $customer->display_ref,
                'full_name' => $customer->full_name,
                'phone' => $customer->phone,
                'status' => $customer->status instanceof \BackedEnum ? $customer->status->value : (string) $customer->status,
            ],
            'notice_account' => $t->noticeAccount === null ? null : $account($t->noticeAccount) + [
                'provider_fee_percent' => $t->noticeAccount->provider_fee_percent === null ? null : bcadd((string) $t->noticeAccount->provider_fee_percent, '0', 3),
            ],
            'receiving_account' => $account($t->receivingAccount),
            'hold_note' => $t->hold_note,
            'held_by' => $staff($t->heldBy),
            'held_at' => $t->held_at?->toIso8601String(),
            'reject_reason' => $t->reject_reason?->value,
            'reject_note' => $t->reject_note,
            'rejected_by' => $staff($t->rejectedBy),
            'rejected_at' => $t->rejected_at?->toIso8601String(),
            'cancelled_at' => $t->cancelled_at?->toIso8601String(),
            'credit_note' => $t->credit_note,
            'arrival_reference' => $t->arrival_reference,
            'credited_by' => $staff($t->creditedBy),
            'credited_at' => $t->credited_at?->toIso8601String(),
            'ledger_txn_id' => $t->ledger_txn_id,
            'allowed_actions' => self::allowedActions($t->status),
        ];
    }

    /** @return list<string> */
    public static function allowedActions(TopUpStatus $status): array
    {
        return array_values(array_filter([
            $status->canMoveTo(TopUpStatus::CREDITED) ? 'match' : null,
            $status->canMoveTo(TopUpStatus::ON_HOLD) ? 'hold' : null,
            $status === TopUpStatus::ON_HOLD ? 'unhold' : null,
            $status->canMoveTo(TopUpStatus::REJECTED) ? 'reject' : null,
        ]));
    }
}
