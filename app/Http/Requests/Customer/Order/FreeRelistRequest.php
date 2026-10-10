<?php

namespace App\Http\Requests\Customer\Order;

use App\Http\Requests\Customer\Listing\ListingRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * The buyer relists a collected piece for free (spec 018 FR-005, FR-007). The
 * price field the category needs is checked by the Action (the category is
 * the origin listing's, which the buyer's row isolation does not expose here).
 */
#[OA\Schema(
    schema: 'FreeRelistRequest',
    description: "Spec 018. Exactly the field the piece's category needs: making_charge_per_g for gold, asking_price for a stone or gold with a stone. The karat, the weight, the photos and the branch options are copied from the sale.",
    required: ['ownership_legal_doc_id'],
    properties: [
        new OA\Property(property: 'making_charge_per_g', type: 'string', nullable: true, example: '250.00', description: 'EGP per gram, >= 0, at most 2 decimals; gold only'),
        new OA\Property(property: 'asking_price', type: 'string', nullable: true, example: '120000.00', description: 'EGP, > 0, at most 2 decimals; diamond and gold_with_diamond only'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: 'At most 2000 characters'),
        new OA\Property(property: 'ownership_legal_doc_id', type: 'integer', description: 'The id of the current ownership declaration (GET /reference/legal-documents/ownership_declaration)'),
    ],
)]
class FreeRelistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'making_charge_per_g' => ['sometimes', 'nullable', ...ListingRules::MONEY, 'gte:0'],
            'asking_price' => ['sometimes', 'nullable', ...ListingRules::MONEY, 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('dahab-listings.description_max')],
            'ownership_legal_doc_id' => ['required', 'integer'],
        ];
    }
}
