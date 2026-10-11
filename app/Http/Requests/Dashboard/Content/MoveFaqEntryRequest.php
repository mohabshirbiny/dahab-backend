<?php

namespace App\Http\Requests\Dashboard\Content;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardMoveFaqEntry',
    required: ['position'],
    properties: [
        new OA\Property(property: 'position', type: 'integer', minimum: 1, description: '1 = first; past the end means last'),
    ],
)]
class MoveFaqEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:1', 'max:1000']];
    }
}
