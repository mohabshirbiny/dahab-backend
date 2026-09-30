<?php

namespace App\Http\Requests\Customer\Listing;

use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreListingRequest',
    description: 'A new listing (spec 010, Part 2 §3). Which fields apply depends on the category: gold — karat_code, stated_weight_g, making_charge_per_g; gold_with_diamond — karat_code, stated_weight_g, asking_price; diamond — asking_price only. A field that does not apply must be absent or null.',
    required: ['category', 'piece_type_id', 'branch_option_ids', 'ownership_declaration_accepted', 'ownership_legal_doc_id'],
    properties: [
        new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']),
        new OA\Property(property: 'piece_type_id', type: 'integer', description: 'An enabled piece type of that category (GET /reference/piece-types)'),
        new OA\Property(property: 'karat_code', type: 'integer', nullable: true, example: 21, description: 'An enabled karat (GET /reference/karats); required unless diamond'),
        new OA\Property(property: 'stated_weight_g', type: 'string', nullable: true, example: '8.000', description: 'Grams, > 0, at most 3 decimals; required unless diamond'),
        new OA\Property(property: 'making_charge_per_g', type: 'string', nullable: true, example: '250.00', description: 'EGP per gram, >= 0, at most 2 decimals; gold only'),
        new OA\Property(property: 'asking_price', type: 'string', nullable: true, example: '120000.00', description: 'EGP, > 0, at most 2 decimals; diamond and gold_with_diamond only'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: 'At most 2000 characters; 40 to 2000 are needed to send the listing for review'),
        new OA\Property(property: 'branch_option_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'At least one enabled branch the seller is willing to bring the piece to (GET /reference/branches)'),
        new OA\Property(property: 'photo_tokens', type: 'array', items: new OA\Items(type: 'string'), description: 'Upload tokens with purpose listing_photo, in display order; at most 6'),
        new OA\Property(property: 'video_token', type: 'string', nullable: true, description: 'Upload token, purpose listing_video'),
        new OA\Property(property: 'invoice_token', type: 'string', nullable: true, description: 'Upload token, purpose listing_invoice; the invoice stays private'),
        new OA\Property(property: 'stone_certificate_token', type: 'string', nullable: true, description: 'Upload token, purpose stone_certificate; public once the listing is live'),
        new OA\Property(property: 'ownership_declaration_accepted', type: 'boolean', description: 'Must be true'),
        new OA\Property(property: 'ownership_legal_doc_id', type: 'integer', description: 'The id of the current ownership declaration (GET /reference/legal-documents/ownership_declaration)'),
    ],
)]
class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Three refusals carry their own code (Part 2 §3) instead of
     * validation_failed, so they are decided before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $category = PieceCategory::tryFrom((string) $this->input('category'));

        if ($category !== null && $category !== PieceCategory::DIAMOND
            && (blank($this->input('karat_code')) || blank($this->input('stated_weight_g')))) {
            throw DomainApiException::goldNeedsKaratWeight();
        }

        $branches = $this->input('branch_option_ids');
        if (! is_array($branches) || $branches === []) {
            throw DomainApiException::branchOptionsRequired();
        }

        if ($this->input('ownership_declaration_accepted') !== true) {
            throw DomainApiException::ownershipDeclarationRequired();
        }
    }

    public function rules(): array
    {
        $category = PieceCategory::tryFrom((string) $this->input('category'));

        return [
            'category' => ['required', Rule::enum(PieceCategory::class)],
            ...($category === null ? [] : ListingRules::for($category, partial: false)),
            'branch_option_ids' => ['required', 'array', 'min:1', 'max:50'],
            'branch_option_ids.*' => ['integer', 'distinct'],
            'photo_tokens' => ['sometimes', 'array', 'max:'.config('dahab-listings.max_photos')],
            'photo_tokens.*' => ['string', 'max:200', 'distinct'],
            'video_token' => ['sometimes', 'nullable', 'string', 'max:200'],
            'invoice_token' => ['sometimes', 'nullable', 'string', 'max:200'],
            'stone_certificate_token' => ['sometimes', 'nullable', 'string', 'max:200'],
            'ownership_declaration_accepted' => ['required', 'accepted'],
            'ownership_legal_doc_id' => ['required', 'integer'],
        ];
    }
}
