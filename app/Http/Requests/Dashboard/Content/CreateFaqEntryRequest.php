<?php

namespace App\Http\Requests\Dashboard\Content;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardCreateFaqEntry',
    required: ['slug', 'question_en', 'question_ar', 'answer_en', 'answer_ar'],
    properties: [
        new OA\Property(property: 'slug', type: 'string', pattern: '^[a-z0-9_]{2,40}$', example: 'how_to_sell', description: 'The entry\'s name inside the keys help.faq.<slug>.question / .answer; never changes'),
        new OA\Property(property: 'question_en', type: 'string'),
        new OA\Property(property: 'question_ar', type: 'string'),
        new OA\Property(property: 'answer_en', type: 'string'),
        new OA\Property(property: 'answer_ar', type: 'string'),
        new OA\Property(property: 'is_hidden', type: 'boolean', description: 'Keep it out of the app when published'),
    ],
)]
class CreateFaqEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Both languages and lengths are the content rules' business (their own codes).
        return [
            'slug' => ['required', 'string', 'regex:/^[a-z0-9_]{2,40}$/'],
            'question_en' => ['present', 'nullable', 'string', 'max:20000'],
            'question_ar' => ['present', 'nullable', 'string', 'max:20000'],
            'answer_en' => ['present', 'nullable', 'string', 'max:20000'],
            'answer_ar' => ['present', 'nullable', 'string', 'max:20000'],
            'is_hidden' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{question_en: string, question_ar: string, answer_en: string, answer_ar: string, is_hidden: bool} */
    public function texts(): array
    {
        return [
            'question_en' => (string) $this->input('question_en'),
            'question_ar' => (string) $this->input('question_ar'),
            'answer_en' => (string) $this->input('answer_en'),
            'answer_ar' => (string) $this->input('answer_ar'),
            'is_hidden' => $this->boolean('is_hidden'),
        ];
    }
}
