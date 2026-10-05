<?php

namespace App\Http\Requests\Dashboard\Invoices;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Issue a credit note against a seller invoice (spec 016 FR-019, FR-020). */
#[OA\Schema(
    schema: 'IssueCreditNoteRequest',
    required: ['amount', 'reason'],
    properties: [
        new OA\Property(property: 'amount', type: 'string', example: '300.00', description: 'The gross to credit (commission + VAT), > 0, at most 2 decimals, never above what is left of the invoice'),
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000, example: 'Commission was overstated after a weight correction.'),
    ],
)]
class IssueCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
