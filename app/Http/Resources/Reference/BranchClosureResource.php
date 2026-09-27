<?php

namespace App\Http\Resources\Reference;

use App\Models\BranchClosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardBranchClosure',
    description: 'A full day a branch — or every branch — is closed (spec 004).',
    required: ['id', 'branch_id', 'closure_date', 'reason_en', 'reason_ar'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'null = every branch'),
        new OA\Property(property: 'closure_date', type: 'string', format: 'date'),
        new OA\Property(property: 'reason_en', type: 'string', nullable: true),
        new OA\Property(property: 'reason_ar', type: 'string', nullable: true),
    ],
)]
class BranchClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BranchClosure $c */
        $c = $this->resource;

        return [
            'id' => $c->closure_id,
            'branch_id' => $c->branch_id,
            'closure_date' => $c->closure_date->toDateString(),
            'reason_en' => $c->reason_en,
            'reason_ar' => $c->reason_ar,
        ];
    }
}
