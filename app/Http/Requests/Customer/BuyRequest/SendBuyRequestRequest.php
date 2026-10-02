<?php

namespace App\Http\Requests\Customer\BuyRequest;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Send a buy request (spec 011 FR-001–FR-008; Part 2 §4). The price the buyer
 * was shown, and the id of the deposit terms they accepted (must be the
 * current version).
 */
#[OA\Schema(
    schema: 'SendBuyRequestRequest',
    required: ['listing_id', 'confirm_locked_price', 'deposit_legal_doc_id'],
    properties: [
        new OA\Property(property: 'listing_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'confirm_locked_price', type: 'string', example: '58200.0000', description: 'The current_price the buyer saw. Refused as price_moved when the fresh price is further away than buyrequest.price_tolerance_pct; otherwise the fresh price is locked.'),
        new OA\Property(property: 'deposit_legal_doc_id', type: 'integer', example: 2, description: 'Id of the current deposit_agreement (GET /reference/legal-documents/deposit_agreement)'),
    ],
)]
class SendBuyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'uuid'],
            'confirm_locked_price' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'deposit_legal_doc_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
