<?php

namespace App\Http\Requests\Dashboard\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * What IGI measured (spec 012 FR-011, research R9). The client never sends
 * the outcome: the server derives it. Gold and gold-with-diamond need the
 * measured karat and weight (checked against the order's piece by the
 * Action, which knows the category).
 */
#[OA\Schema(
    schema: 'RecordInspectionResultRequest',
    properties: [
        new OA\Property(property: 'measured_karat', type: 'integer', nullable: true, example: 21),
        new OA\Property(property: 'measured_weight_g', type: 'string', nullable: true, example: '9.900', description: 'Grams, up to 3 decimals, > 0'),
        new OA\Property(property: 'measured_stone_grade', type: 'string', nullable: true, maxLength: 100),
        new OA\Property(property: 'certificate_number', type: 'string', nullable: true, maxLength: 100),
        new OA\Property(property: 'inspector_note', type: 'string', nullable: true, maxLength: 2000),
        new OA\Property(property: 'is_counterfeit', type: 'boolean', default: false),
        new OA\Property(property: 'stone_below_claim', type: 'boolean', default: false, description: 'Stone categories only'),
        new OA\Property(property: 'supersedes_id', type: 'string', format: 'uuid', nullable: true, description: 'Only to correct the latest result'),
    ],
)]
class RecordInspectionResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'measured_karat' => ['nullable', 'integer', 'exists:karat,karat_code'],
            'measured_weight_g' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999999'],
            'measured_stone_grade' => ['nullable', 'string', 'max:100'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'inspector_note' => ['nullable', 'string', 'max:2000'],
            'is_counterfeit' => ['sometimes', 'boolean'],
            'stone_below_claim' => ['sometimes', 'boolean'],
            'supersedes_id' => ['nullable', 'uuid'],
        ];
    }
}
