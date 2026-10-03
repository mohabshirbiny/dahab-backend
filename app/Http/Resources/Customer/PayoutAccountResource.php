<?php

namespace App\Http\Resources\Customer;

use App\Enums\PayoutAccountState;
use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerPayoutAccount',
    description: 'One of the customer\'s own payout accounts (spec 013). The number is masked; the refusal note and the checker are never shown.',
    required: ['id', 'bank_name', 'account_name', 'number_masked', 'kind', 'state', 'in_use', 'added_at', 'checked_at', 'refusal_reason', 'can'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_name', type: 'string', example: 'CIB'),
        new OA\Property(property: 'account_name', type: 'string', example: 'Mona Hassan Ibrahim'),
        new OA\Property(property: 'number_masked', type: 'string', example: '•••• 4417'),
        new OA\Property(property: 'kind', type: 'string', enum: ['iban', 'account_number']),
        new OA\Property(property: 'state', type: 'string', enum: ['pending_review', 'active', 'refused', 'removing', 'removed']),
        new OA\Property(property: 'in_use', type: 'boolean', description: 'Withdrawals go to this account'),
        new OA\Property(property: 'added_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'checked_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'refusal_reason', type: 'string', nullable: true, enum: ['name_mismatch', 'name_shortened', 'not_in_customer_name', 'details_invalid', 'other']),
        new OA\Property(property: 'can', properties: [
            new OA\Property(property: 'use', type: 'boolean', description: 'Verified and not in use: making it the one in use pauses withdrawals'),
            new OA\Property(property: 'remove', type: 'boolean', description: 'Under review (cancel the request) or verified'),
            new OA\Property(property: 'keep', type: 'boolean', description: 'Being removed: keep it after all'),
        ], type: 'object'),
    ],
)]
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PayoutAccount $a */
        $a = $this->resource;

        return [
            'id' => $a->payout_account_id,
            'bank_name' => $a->bank_name,
            'account_name' => $a->account_name,
            'number_masked' => $a->masked(),
            'kind' => $a->kind(),
            'state' => $a->state->value,
            'in_use' => $a->is_in_use,
            'added_at' => $a->created_at?->toIso8601String(),
            'checked_at' => $a->state === PayoutAccountState::ACTIVE || $a->state === PayoutAccountState::REMOVING
                ? $a->name_checked_at?->toIso8601String() : null,
            'refusal_reason' => $a->refusal_reason?->value,
            'can' => [
                'use' => $a->state === PayoutAccountState::ACTIVE && ! $a->is_in_use,
                'remove' => in_array($a->state, [PayoutAccountState::PENDING_REVIEW, PayoutAccountState::ACTIVE], true),
                'keep' => $a->state === PayoutAccountState::REMOVING,
            ],
        ];
    }
}
