<?php

namespace App\Http\Requests\Customer\Order;

use App\Enums\DisputeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** A party reports a problem on an order (spec 014 FR-001). */
#[OA\Schema(
    schema: 'OpenDisputeRequest',
    required: ['reason', 'detail'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['not_as_listed', 'disagree_inspection', 'money_wrong', 'other_side_unresponsive', 'not_theirs_to_sell', 'other'], description: 'not_theirs_to_sell: the buyer only'),
        new OA\Property(property: 'detail', type: 'string', minLength: 10, maxLength: 2000),
        new OA\Property(property: 'photo_tokens', type: 'array', maxItems: 5, items: new OA\Items(type: 'string'), description: 'upload_tokens of purpose dispute_photo, each used once'),
    ],
)]
class OpenDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(DisputeReason::class)],
            'detail' => ['required', 'string', 'min:10', 'max:2000'],
            'photo_tokens' => ['sometimes', 'array', 'max:5'],
            'photo_tokens.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }

    public function reason(): DisputeReason
    {
        return DisputeReason::from((string) $this->validated('reason'));
    }

    /** @return list<string> */
    public function photoTokens(): array
    {
        return array_values($this->validated('photo_tokens', []));
    }
}
