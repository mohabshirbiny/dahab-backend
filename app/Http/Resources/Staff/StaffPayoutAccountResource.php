<?php

namespace App\Http\Resources\Staff;

use App\Enums\CustomerStatus;
use App\Enums\StaffPermission;
use App\Models\PayoutAccount;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffPayoutAccount',
    description: 'A payout account for staff (spec 013). The full number only for holders of payout_account.verify or withdrawal.release; masked otherwise. `customer` is included in the review list, not in the Customer file.',
    required: ['id', 'bank_name', 'account_name', 'number', 'number_masked', 'kind', 'state', 'in_use', 'added_at', 'checked', 'refusal'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_name', type: 'string'),
        new OA\Property(property: 'account_name', type: 'string'),
        new OA\Property(property: 'number', type: 'string', nullable: true, description: 'Full number; null without payout_account.verify / withdrawal.release'),
        new OA\Property(property: 'number_masked', type: 'string', example: '•••• 4417'),
        new OA\Property(property: 'kind', type: 'string', enum: ['iban', 'account_number']),
        new OA\Property(property: 'state', type: 'string', enum: ['pending_review', 'active', 'refused', 'removing', 'removed']),
        new OA\Property(property: 'in_use', type: 'boolean'),
        new OA\Property(property: 'added_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'checked', nullable: true, properties: [
            new OA\Property(property: 'by', type: 'string', nullable: true),
            new OA\Property(property: 'at', type: 'string', format: 'date-time'),
        ], type: 'object'),
        new OA\Property(property: 'refusal', nullable: true, properties: [
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'note', type: 'string', nullable: true),
        ], type: 'object'),
        new OA\Property(property: 'customer', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'display_ref', type: 'string'),
            new OA\Property(property: 'full_name', type: 'string', nullable: true, description: 'The name on the verified ID'),
            new OA\Property(property: 'identity_verified', type: 'boolean'),
            new OA\Property(property: 'suspended', type: 'boolean'),
        ], type: 'object'),
    ],
)]
class StaffPayoutAccountResource extends JsonResource
{
    public bool $withCustomer = false;

    public static function fullNumberFor(?Staff $staff): bool
    {
        return $staff !== null && ($staff->hasPermissionTo(StaffPermission::PAYOUT_ACCOUNT_VERIFY->value, 'staff')
            || $staff->hasPermissionTo(StaffPermission::WITHDRAWAL_RELEASE->value, 'staff'));
    }

    public function toArray(Request $request): array
    {
        /** @var PayoutAccount $a */
        $a = $this->resource;
        $full = self::fullNumberFor($request->user('staff'));

        $out = [
            'id' => $a->payout_account_id,
            'bank_name' => $a->bank_name,
            'account_name' => $a->account_name,
            'number' => $full ? $a->account_number_or_iban : null,
            'number_masked' => $a->masked(),
            'kind' => $a->kind(),
            'state' => $a->state->value,
            'in_use' => $a->is_in_use,
            'added_at' => $a->created_at?->toIso8601String(),
            'checked' => $a->name_checked_at === null ? null : [
                'by' => $a->checkedBy?->full_name,
                'at' => $a->name_checked_at->toIso8601String(),
            ],
            'refusal' => $a->refusal_reason === null ? null : [
                'reason' => $a->refusal_reason->value,
                'note' => $a->refusal_note,
            ],
        ];

        if ($this->withCustomer) {
            $c = $a->customer;
            $out['customer'] = $c === null ? null : [
                'id' => $c->customer_id,
                'display_ref' => $c->display_ref,
                'full_name' => $c->full_name,
                'identity_verified' => (bool) $c->is_verified,
                'suspended' => $c->status === CustomerStatus::SUSPENDED,
            ];
        }

        return $out;
    }
}
