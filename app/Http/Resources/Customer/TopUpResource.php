<?php

namespace App\Http\Resources\Customer;

use App\Enums\TopUpStatus;
use App\Models\TopUp;
use App\Support\TopUpMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerTopUp',
    description: 'A customer\'s own transfer notice or hand credit (spec 009). Never includes staff notes, the arrival reference or receiving-account details.',
    required: ['id', 'number', 'method', 'reference', 'status', 'claimed_amount', 'expected_amount', 'credited_amount', 'has_receipt', 'submitted_at', 'credited_at', 'reject_reason', 'can_cancel'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'TOP-128'),
        new OA\Property(property: 'method', type: 'string', enum: ['bank_transfer', 'instapay', 'vodafone_cash']),
        new OA\Property(property: 'reference', type: 'string', example: 'DAHAB-004417'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'on_hold', 'credited', 'rejected', 'cancelled']),
        new OA\Property(property: 'claimed_amount', type: 'string', nullable: true, example: '20000.0000', description: 'What the customer said they sent; null for a hand credit'),
        new OA\Property(property: 'expected_amount', type: 'string', nullable: true, example: '9900.0000', description: 'Display-only estimate of what reaches Dahab after the provider fee the account showed when the notice was filed (a snapshot: later fee changes never move it); null when there was no fee or the notice is closed. Staff credit what actually arrives'),
        new OA\Property(property: 'credited_amount', type: 'string', nullable: true, example: '19900.0000', description: 'What actually arrived and was credited'),
        new OA\Property(property: 'has_receipt', type: 'boolean'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'credited_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reject_reason', type: 'string', nullable: true, enum: ['money_not_received', 'duplicate_notice', 'sender_not_accepted', 'other']),
        new OA\Property(property: 'can_cancel', type: 'boolean', description: 'Only a pending notice can be cancelled'),
    ],
)]
class TopUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TopUp $t */
        $t = $this->resource;

        return [
            'id' => $t->topup_id,
            'number' => $t->number(),
            'method' => $t->method->value,
            'reference' => $t->reference,
            'status' => $t->status->value,
            'claimed_amount' => TopUpMoney::format($t->claimed_amount),
            'expected_amount' => TopUpMoney::format($t->expectedAmount()),
            'credited_amount' => TopUpMoney::format($t->credited_amount),
            'has_receipt' => $t->receipt_ref !== null,
            'submitted_at' => $t->submitted_at->toIso8601String(),
            'credited_at' => $t->credited_at?->toIso8601String(),
            'reject_reason' => $t->status === TopUpStatus::REJECTED ? $t->reject_reason?->value : null,
            'can_cancel' => $t->status->canMoveTo(TopUpStatus::CANCELLED),
        ];
    }
}
