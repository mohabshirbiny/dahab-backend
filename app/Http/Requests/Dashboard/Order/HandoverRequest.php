<?php

namespace App\Http\Requests\Dashboard\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * The code a customer shows at the counter (spec 012 FR-019, FR-020, research
 * R10). Spec 014: who collects a paid piece — the buyer, or the proxy they
 * named, whose ID staff confirm they checked. Ignored on a seller return.
 */
#[OA\Schema(
    schema: 'HandoverRequest',
    required: ['code'],
    properties: [
        new OA\Property(property: 'code', type: 'string', pattern: '^[0-9]{6}$', example: '482913'),
        new OA\Property(property: 'collector', type: 'string', enum: ['buyer', 'proxy'], default: 'buyer', description: 'Spec 014: the person at the counter'),
        new OA\Property(property: 'proxy_id_checked', type: 'boolean', default: false, description: 'Spec 014: required true when collector = proxy — the ID was checked against the named proxy'),
    ],
)]
class HandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
            'collector' => ['sometimes', 'string', 'in:buyer,proxy'],
            'proxy_id_checked' => ['sometimes', 'boolean'],
        ];
    }

    public function byProxy(): bool
    {
        return $this->validated('collector', 'buyer') === 'proxy';
    }
}
