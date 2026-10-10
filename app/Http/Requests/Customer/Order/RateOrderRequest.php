<?php

namespace App\Http\Requests\Customer\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** A party rates an order (spec 018 FR-030, FR-031): 1–5 stars and an optional plain-text note. */
#[OA\Schema(
    schema: 'RateOrderRequest',
    required: ['stars'],
    properties: [
        new OA\Property(property: 'stars', type: 'integer', minimum: 1, maximum: 5),
        new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 500, description: 'Plain text; an empty note is stored as none'),
    ],
)]
class RateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stars' => ['required', 'integer', 'between:1,5'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
