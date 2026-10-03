<?php

namespace App\Http\Requests\Customer\Payout;

use App\Support\Withdrawals\Iban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AddPayoutAccountRequest',
    required: ['bank_name', 'account_name', 'account_number_or_iban', 'declaration_id', 'declaration_accepted'],
    properties: [
        new OA\Property(property: 'bank_name', type: 'string', minLength: 2, maxLength: 80, example: 'CIB'),
        new OA\Property(property: 'account_name', type: 'string', minLength: 3, maxLength: 120, example: 'Mona Hassan Ibrahim', description: 'Exactly as on the ID; checked by staff'),
        new OA\Property(property: 'account_number_or_iban', type: 'string', example: 'EG38 0019 0005 0000 0000 2631 8000 2', description: 'An Egyptian IBAN (EG + 27 digits, checksum checked) or an 8–20 digit account number; spaces are ignored'),
        new OA\Property(property: 'declaration_id', type: 'integer', description: 'legal_doc_id of the current payout_account_declaration (GET /reference/legal-documents/payout_account_declaration)'),
        new OA\Property(property: 'declaration_accepted', type: 'boolean', example: true),
    ],
)]
class AddPayoutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('account_number_or_iban'))) {
            $this->merge(['account_number_or_iban' => Iban::normalize($this->input('account_number_or_iban'))]);
        }
        foreach (['bank_name', 'account_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) preg_replace('/\s+/u', ' ', $this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'min:2', 'max:80'],
            'account_name' => ['required', 'string', 'min:3', 'max:120'],
            'account_number_or_iban' => ['required', 'string', 'max:34'],
            'declaration_id' => ['required', 'integer'],
            'declaration_accepted' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $number = (string) $this->input('account_number_or_iban', '');
            if ($number !== '' && ! $v->errors()->has('account_number_or_iban') && ! Iban::isAcceptable($number)) {
                $v->errors()->add('account_number_or_iban', 'Enter an Egyptian IBAN (EG and 27 digits) or an account number of 8 to 20 digits. Check it carefully.');
            }
        }];
    }

    public function messages(): array
    {
        return ['declaration_accepted.accepted' => 'Confirm the account is yours and the name matches your ID.'];
    }
}
