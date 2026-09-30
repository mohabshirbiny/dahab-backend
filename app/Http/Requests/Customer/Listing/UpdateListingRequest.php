<?php

namespace App\Http\Requests\Customer\Listing;

use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateListingRequest',
    description: 'An edit of a draft or of a listing sent back for changes (spec 010). Send only what changes. The category cannot change; the fields of StoreListingRequest follow the listing\'s category. For the video, the invoice and the certificate: a token replaces the file, null removes it, an absent key leaves it.',
    properties: [
        new OA\Property(property: 'piece_type_id', type: 'integer'),
        new OA\Property(property: 'karat_code', type: 'integer'),
        new OA\Property(property: 'stated_weight_g', type: 'string', example: '8.100'),
        new OA\Property(property: 'making_charge_per_g', type: 'string', example: '240.00'),
        new OA\Property(property: 'asking_price', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'branch_option_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Replaces the whole set; at least one enabled branch'),
        new OA\Property(property: 'add_photo_tokens', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'remove_media_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'photo_order', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Every photo id of the listing after this edit, in display order'),
        new OA\Property(property: 'video_token', type: 'string', nullable: true),
        new OA\Property(property: 'invoice_token', type: 'string', nullable: true),
        new OA\Property(property: 'stone_certificate_token', type: 'string', nullable: true),
    ],
)]
class UpdateListingRequest extends FormRequest
{
    private ?Listing $listing = null;

    public function authorize(): bool
    {
        return true;
    }

    /** The seller's own listing (row-level security hides anyone else's): 404 otherwise. */
    public function listing(): Listing
    {
        return $this->listing ??= Listing::query()
            ->where('seller_id', $this->user('customer')->customer_id)
            ->findOrFail((string) $this->route('listing'));
    }

    protected function prepareForValidation(): void
    {
        $listing = $this->listing();

        // The state decides first: a listing that cannot be edited is a 409, whatever was sent.
        if (! $listing->state->isEditable()) {
            throw DomainApiException::listingNotEditable();
        }

        if ($listing->category !== PieceCategory::DIAMOND) {
            foreach (['karat_code', 'stated_weight_g'] as $field) {
                if ($this->has($field) && blank($this->input($field))) {
                    throw DomainApiException::goldNeedsKaratWeight();
                }
            }
        }

        if ($this->has('branch_option_ids') && (! is_array($this->input('branch_option_ids')) || $this->input('branch_option_ids') === [])) {
            throw DomainApiException::branchOptionsRequired();
        }
    }

    public function rules(): array
    {
        return [
            'category' => ['prohibited'],
            ...ListingRules::for($this->listing()->category, partial: true),
            'branch_option_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'branch_option_ids.*' => ['integer', 'distinct'],
            'add_photo_tokens' => ['sometimes', 'array', 'max:'.config('dahab-listings.max_photos')],
            'add_photo_tokens.*' => ['string', 'max:200', 'distinct'],
            'remove_media_ids' => ['sometimes', 'array', 'max:20'],
            'remove_media_ids.*' => ['uuid', 'distinct'],
            'photo_order' => ['sometimes', 'array', 'max:'.config('dahab-listings.max_photos')],
            'photo_order.*' => ['uuid', 'distinct'],
            'video_token' => ['sometimes', 'nullable', 'string', 'max:200'],
            'invoice_token' => ['sometimes', 'nullable', 'string', 'max:200'],
            'stone_certificate_token' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
