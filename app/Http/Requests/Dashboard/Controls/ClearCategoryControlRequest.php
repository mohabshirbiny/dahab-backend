<?php

namespace App\Http\Requests\Dashboard\Controls;

use App\Enums\ControlLevel;
use App\Enums\PieceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardClearCategoryControl',
    required: ['level'],
    properties: [
        new OA\Property(property: 'level', type: 'string', enum: ['stop_new_listings', 'pause_category', 'stop_everything']),
        new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond'], description: 'Required except for stop_everything'),
    ],
)]
class ClearCategoryControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'level' => ['required', Rule::enum(ControlLevel::class)],
            'category' => ['nullable', Rule::enum(PieceCategory::class)],
        ];
    }

    public function level(): ControlLevel
    {
        return ControlLevel::from((string) $this->input('level'));
    }

    public function category(): ?PieceCategory
    {
        return filled($this->input('category')) ? PieceCategory::from((string) $this->input('category')) : null;
    }
}
