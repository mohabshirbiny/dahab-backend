<?php

namespace App\Http\Requests\Dashboard\FounderSecurity;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardConfirmUnfreeze',
    required: ['freeze_id'],
    description: 'One confirmation; the server picks the slot.',
    properties: [
        new OA\Property(property: 'freeze_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
    ],
)]
class ConfirmUnfreezeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'freeze_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
