<?php

namespace App\Http\Resources\Staff;

use App\Models\ReceivingAccount;
use App\Support\TopUpMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffReceivingAccount',
    description: 'One of Dahab\'s receiving accounts as staff manage it (spec 009 US4).',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'method', type: 'string', enum: ['bank_transfer', 'instapay', 'vodafone_cash']),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'bank_name', type: 'string', nullable: true),
        new OA\Property(property: 'account_holder', type: 'string', nullable: true),
        new OA\Property(property: 'account_number', type: 'string', nullable: true),
        new OA\Property(property: 'iban', type: 'string', nullable: true),
        new OA\Property(property: 'instapay_address', type: 'string', nullable: true),
        new OA\Property(property: 'wallet_number', type: 'string', nullable: true),
        new OA\Property(property: 'daily_limit', type: 'string', nullable: true),
        new OA\Property(property: 'provider_fee_percent', type: 'string', nullable: true),
        new OA\Property(property: 'customer_note', type: 'string', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
    ],
)]
class ReceivingAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ReceivingAccount $a */
        $a = $this->resource;
        $by = $a->updatedBy;

        return [
            'id' => $a->receiving_account_id,
            'method' => $a->method->value,
            'label' => $a->label,
            'bank_name' => $a->bank_name,
            'account_holder' => $a->account_holder,
            'account_number' => $a->account_number,
            'iban' => $a->iban,
            'instapay_address' => $a->instapay_address,
            'wallet_number' => $a->wallet_number,
            'daily_limit' => TopUpMoney::format($a->daily_limit),
            'provider_fee_percent' => $a->provider_fee_percent === null ? null : bcadd((string) $a->provider_fee_percent, '0', 3),
            'customer_note' => $a->customer_note,
            'sort_order' => $a->sort_order,
            'is_active' => $a->is_active,
            'updated_at' => $a->updated_at->toIso8601String(),
            'updated_by' => $by === null ? null : ['id' => $by->staff_id, 'full_name' => $by->full_name],
        ];
    }
}
