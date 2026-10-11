<?php

namespace App\Http\Requests\Dashboard\FounderSecurity;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardDeviceApprovalDecision',
    description: 'Spec 021: an optional note, stored on the request and as the audit reason.',
    properties: [
        new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true, example: 'Called Mona, it is her new laptop'),
    ],
)]
class DecideDeviceApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
