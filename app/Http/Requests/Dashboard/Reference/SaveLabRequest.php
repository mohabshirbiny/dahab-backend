<?php

namespace App\Http\Requests\Dashboard\Reference;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** A new lab (POST: names and the three certificate costs required) or any subset of its fields (PATCH). */
#[OA\Schema(
    schema: 'DashboardSaveLab',
    properties: [
        new OA\Property(property: 'name_en', type: 'string', maxLength: 150),
        new OA\Property(property: 'name_ar', type: 'string', maxLength: 150),
        new OA\Property(property: 'certificate_own_cost_egp', type: 'string', description: 'EGP when the seller holds this lab certificate; >= 0, at most 2 decimals'),
        new OA\Property(property: 'certificate_other_cost_egp', type: 'string', description: 'EGP when the seller holds another lab certificate'),
        new OA\Property(property: 'certificate_none_cost_egp', type: 'string', description: 'EGP when the seller holds no certificate'),
        new OA\Property(property: 'gold_inspection_cost_egp', type: 'string', description: 'EGP to inspect a gold piece; default 0, and 0 appears nowhere'),
        new OA\Property(property: 'is_enabled', type: 'boolean', description: 'false: its live pieces leave the market until their sellers choose another lab'),
    ],
)]
class SaveLabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $need = $this->isMethod('post') ? 'required' : 'sometimes';
        $money = ['numeric', 'decimal:0,2', 'gte:0', 'max:99999999.99'];

        return [
            'name_en' => [$need, 'string', 'max:150'],
            'name_ar' => [$need, 'string', 'max:150'],
            'certificate_own_cost_egp' => [$need, ...$money],
            'certificate_other_cost_egp' => [$need, ...$money],
            'certificate_none_cost_egp' => [$need, ...$money],
            'gold_inspection_cost_egp' => ['sometimes', ...$money],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
