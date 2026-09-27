<?php

namespace App\Http\Requests\Dashboard\Reference;

use App\Support\WorkingHours\PlatformCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

/**
 * `branch_id` null closes every branch (a national holiday). The date must
 * be today or later, in the branch's timezone (the platform's for all
 * branches). A duplicate is refused by the Action (409 closure_exists).
 */
#[OA\Schema(
    schema: 'DashboardStoreBranchClosure',
    required: ['branch_id', 'closure_date'],
    properties: [
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'null = every branch'),
        new OA\Property(property: 'closure_date', type: 'string', format: 'date', example: '2026-10-06'),
        new OA\Property(property: 'reason_en', type: 'string', maxLength: 200, nullable: true),
        new OA\Property(property: 'reason_ar', type: 'string', maxLength: 200, nullable: true),
    ],
)]
class StoreBranchClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['present', 'nullable', 'integer', 'exists:branch,branch_id'],
            'closure_date' => ['required', 'date_format:Y-m-d'],
            'reason_en' => ['sometimes', 'nullable', 'string', 'max:200'],
            'reason_ar' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $branchId = $this->input('branch_id');
            if ($this->input('closure_date') < PlatformCalendar::todayFor($branchId === null ? null : (int) $branchId)) {
                $validator->errors()->add('closure_date', 'The date must be today or later.');
            }
        }];
    }
}
