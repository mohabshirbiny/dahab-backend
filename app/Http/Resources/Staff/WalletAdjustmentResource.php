<?php

namespace App\Http\Resources\Staff;

use App\Models\WalletAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** One wallet adjustment (spec 015 FR-006). */
#[OA\Schema(
    schema: 'StaffWalletAdjustment',
    required: ['id', 'adjusted_at', 'customer', 'direction', 'amount', 'reason', 'customer_status', 'adjusted_by', 'ledger_txn_id'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'adjusted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'customer', type: 'object', description: '{id, display_ref, name}'),
        new OA\Property(property: 'direction', type: 'string', enum: ['credit', 'debit']),
        new OA\Property(property: 'amount', type: 'string', example: '500.0000'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'customer_status', type: 'string', description: 'The customer\'s status at the time'),
        new OA\Property(property: 'adjusted_by', type: 'object', description: '{id, name}'),
        new OA\Property(property: 'ledger_txn_id', type: 'string', format: 'uuid'),
    ],
)]
class WalletAdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WalletAdjustment $a */
        $a = $this->resource;

        return [
            'id' => $a->adjustment_id,
            'adjusted_at' => $a->adjusted_at->toIso8601String(),
            'customer' => ['id' => $a->customer_id, 'display_ref' => $a->customer?->display_ref, 'name' => $a->customer?->full_name],
            'direction' => $a->direction->value,
            'amount' => bcadd((string) $a->amount, '0', 4),
            'reason' => $a->reason,
            'customer_status' => $a->customer_status,
            'adjusted_by' => ['id' => $a->adjusted_by, 'name' => $a->adjuster?->full_name],
            'ledger_txn_id' => $a->ledger_txn_id,
        ];
    }
}
