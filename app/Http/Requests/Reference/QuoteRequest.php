<?php

namespace App\Http\Requests\Reference;

use App\Enums\CertificateKind;
use App\Enums\PieceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The seller's "what will I get" quote (spec 015 FR-021). */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = ['string', 'regex:/^\d{1,12}(\.\d{1,4})?$/', 'not_regex:/^0+(\.0+)?$/'];

        return [
            'category' => ['required', 'string', Rule::enum(PieceCategory::class)],
            'karat' => ['exclude_if:category,diamond', 'required', 'integer'],
            'weight_g' => ['exclude_if:category,diamond', 'required', 'string', 'regex:/^\d{1,5}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/',
                fn ($attr, $value, $fail) => bccomp((string) $value, '10000', 3) > 0 ? $fail('The weight can be at most 10,000 g.') : null],
            'making_per_g' => ['exclude_unless:category,gold', 'sometimes', 'string', 'regex:/^\d{1,9}(\.\d{1,4})?$/'],
            'asking_price' => ['exclude_if:category,gold', 'required', ...$money],
            'certificate' => ['exclude_if:category,gold', 'sometimes', 'nullable', 'string', Rule::in([...array_column(CertificateKind::cases(), 'value'), CertificateKind::LEGACY_IGI])],
            'certificate_lab_id' => ['exclude_if:category,gold', 'sometimes', 'nullable', 'integer', 'exists:lab,lab_id'],
            'lab_id' => ['sometimes', 'nullable', 'integer', 'exists:lab,lab_id'],
        ];
    }

    public function certificate(): ?CertificateKind
    {
        $value = (string) $this->validated('certificate');

        return $value === CertificateKind::LEGACY_IGI ? CertificateKind::LAB : CertificateKind::tryFrom($value);
    }

    public function category(): PieceCategory
    {
        return PieceCategory::from($this->validated('category'));
    }
}
