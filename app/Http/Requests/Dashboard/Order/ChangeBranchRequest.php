<?php

namespace App\Http\Requests\Dashboard\Order;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Move an open order to another of the seller's branches (spec 012 FR-008). */
#[OA\Schema(
    schema: 'ChangeBranchRequest',
    required: ['branch_id', 'reason'],
    properties: [
        new OA\Property(property: 'branch_id', type: 'integer', description: 'One of the listing\'s named, enabled branches'),
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000),
        new OA\Property(property: 'extend_to', type: 'string', format: 'date-time', nullable: true, description: 'Optional: a later reach-branch deadline; otherwise the clock keeps running'),
    ],
)]
class ChangeBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'between:10,1000'],
            'extend_to' => ['nullable', 'date'],
        ];
    }

    public function extendTo(): ?CarbonImmutable
    {
        $at = $this->validated('extend_to');

        return $at === null ? null : CarbonImmutable::parse($at);
    }
}
