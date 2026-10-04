<?php

namespace App\Http\Requests\Dashboard\Dispute;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Pass a dispute to a named colleague (spec 014 FR-009). */
#[OA\Schema(
    schema: 'PassOnDisputeRequest',
    required: ['assignee_id', 'note'],
    properties: [
        new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid', description: 'From GET /dashboard/dispute-assignees'),
        new OA\Property(property: 'note', type: 'string', minLength: 10, maxLength: 1000, description: 'What you need from them — staff only'),
    ],
)]
class PassOnDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
