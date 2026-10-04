<?php

namespace App\Http\Resources\Staff;

use App\Models\DailyClose;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** A stored close of a day (spec 015 FR-014). */
#[OA\Schema(
    schema: 'StaffDailyClose',
    required: ['date', 'bank_balance', 'books_bank', 'difference', 'explanation', 'is_locked', 'saved_by', 'saved_at', 'closed_by', 'closed_at'],
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'bank_balance', type: 'string', description: 'Typed from the statements (all Dahab accounts)'),
        new OA\Property(property: 'books_bank', type: 'string', description: 'The ledger\'s bank cash at the cut-off'),
        new OA\Property(property: 'customer_available', type: 'string'),
        new OA\Property(property: 'customer_held', type: 'string'),
        new OA\Property(property: 'customer_liability', type: 'string'),
        new OA\Property(property: 'dahab_wallet', type: 'string'),
        new OA\Property(property: 'escrow', type: 'string'),
        new OA\Property(property: 'vat_payable', type: 'string'),
        new OA\Property(property: 'movements_in', type: 'string'),
        new OA\Property(property: 'movements_out', type: 'string'),
        new OA\Property(property: 'difference', type: 'string', description: 'bank_balance − books_bank'),
        new OA\Property(property: 'explanation', type: 'string', nullable: true),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'saved_by', type: 'object', description: '{id, name}'),
        new OA\Property(property: 'saved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_by', type: 'object', nullable: true, description: '{id, name}'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class DailyCloseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DailyClose $c */
        $c = $this->resource;
        $m = fn ($v) => bcadd((string) $v, '0', 4);

        return [
            'date' => $c->close_date->toDateString(),
            'bank_balance' => $m($c->bank_balance),
            'books_bank' => $m($c->books_bank),
            'customer_available' => $m($c->customer_available),
            'customer_held' => $m($c->customer_held),
            'customer_liability' => $m($c->customer_liability),
            'dahab_wallet' => $m($c->dahab_wallet),
            'escrow' => $m($c->escrow),
            'vat_payable' => $m($c->vat_payable),
            'movements_in' => $m($c->movements_in),
            'movements_out' => $m($c->movements_out),
            'difference' => $m($c->difference),
            'explanation' => $c->explanation,
            'is_locked' => $c->is_locked,
            'saved_by' => ['id' => $c->saved_by, 'name' => $c->saver?->full_name],
            'saved_at' => $c->saved_at->toIso8601String(),
            'closed_by' => $c->closed_by === null ? null : ['id' => $c->closed_by, 'name' => $c->closer?->full_name],
            'closed_at' => $c->closed_at?->toIso8601String(),
        ];
    }
}
