<?php

namespace App\Http\Requests\Dashboard\Finance;

use App\Enums\CompensationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** Pay compensation outside a dispute (spec 015 FR-004). */
#[OA\Schema(
    schema: 'PayCompensationRequest',
    required: ['customer_id', 'amount', 'reason', 'note'],
    properties: [
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'amount', type: 'string', example: '800', description: '> 0, at most 4 decimal places; capped unless compensation.uncapped'),
        new OA\Property(property: 'reason', type: 'string', enum: ['igi_delay', 'dahab_mistake', 'wasted_trip', 'dispute_settlement', 'goodwill']),
        new OA\Property(property: 'note', type: 'string', minLength: 10, maxLength: 1000, description: 'For the record; never sent to the customer'),
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true, description: 'Optionally one of the customer\'s orders'),
    ],
)]
class PayCompensationRequest extends FormRequest
{
    public const AMOUNT = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/', 'not_regex:/^0+(\.0+)?$/'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', Rule::exists('customer', 'customer_id')],
            'amount' => self::AMOUNT,
            'reason' => ['required', 'string', Rule::enum(CompensationReason::class)],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
            'order_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
