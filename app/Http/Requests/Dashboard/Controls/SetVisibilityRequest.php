<?php

namespace App\Http\Requests\Dashboard\Controls;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardSetVisibility',
    required: ['shown'],
    properties: [new OA\Property(property: 'shown', type: 'boolean')],
)]
class SetVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['shown' => ['required', 'boolean']];
    }
}
