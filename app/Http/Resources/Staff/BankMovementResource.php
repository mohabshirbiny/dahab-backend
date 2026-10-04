<?php

namespace App\Http\Resources\Staff;

use App\Models\BankMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** A bank movement recorded by hand (spec 015 FR-010, FR-011). */
#[OA\Schema(
    schema: 'StaffBankMovement',
    required: ['id', 'number', 'kind', 'kind_label', 'direction', 'amount', 'occurred_on', 'reason', 'has_proof', 'recorded_by', 'recorded_at', 'posts_to_ledger', 'ledger_txn_id'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'BM-12'),
        new OA\Property(property: 'kind', type: 'string', enum: ['capital_in', 'operating_expense', 'bank_charge', 'profit_draw', 'own_transfer', 'supplier_refund', 'other']),
        new OA\Property(property: 'kind_label', type: 'string'),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
        new OA\Property(property: 'amount', type: 'string', description: 'Positive; see direction'),
        new OA\Property(property: 'occurred_on', type: 'string', format: 'date'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'has_proof', type: 'boolean'),
        new OA\Property(property: 'recorded_by', type: 'object', description: '{id, name}'),
        new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'posts_to_ledger', type: 'boolean', description: 'False for an own-account transfer'),
        new OA\Property(property: 'ledger_txn_id', type: 'string', format: 'uuid', nullable: true),
    ],
)]
class BankMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BankMovement $m */
        $m = $this->resource;

        return [
            'id' => $m->movement_id,
            'number' => $m->number(),
            'kind' => $m->kind->value,
            'kind_label' => $m->kind->label(),
            'direction' => $m->direction(),
            'amount' => $m->magnitude(),
            'occurred_on' => $m->occurred_on->toDateString(),
            'reason' => $m->reason,
            'has_proof' => $m->proof_ref !== null,
            'recorded_by' => ['id' => $m->recorded_by, 'name' => $m->recorder?->full_name],
            'recorded_at' => $m->recorded_at->toIso8601String(),
            'posts_to_ledger' => $m->ledger_txn_id !== null,
            'ledger_txn_id' => $m->ledger_txn_id,
        ];
    }
}
