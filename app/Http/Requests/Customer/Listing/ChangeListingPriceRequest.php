<?php

namespace App\Http\Requests\Customer\Listing;

use App\Enums\ListingPriceField;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ChangeListingPriceRequest',
    description: 'The new figure of a live piece (spec 022). Gold sends `making_charge_per_g`; a diamond or gold with diamond sends `asking_price`; the other is refused. It must differ from the current figure.',
    properties: [
        new OA\Property(property: 'making_charge_per_g', type: 'string', example: '220.00', description: 'Gold only; at most 2 decimals, 0 or more'),
        new OA\Property(property: 'asking_price', type: 'string', example: '118000.00', description: 'Diamond and gold with diamond only; at most 2 decimals, more than 0'),
    ],
)]
class ChangeListingPriceRequest extends FormRequest
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

    public function field(): ListingPriceField
    {
        return $this->listing()->priceField();
    }

    /** The new figure as typed. */
    public function figure(): string
    {
        return (string) $this->validated()[$this->field()->value];
    }

    protected function prepareForValidation(): void
    {
        $state = $this->listing()->state;

        // The state decides first, like PATCH: a refused state never reports field errors.
        if ($state->holdsRequests()) {
            throw DomainApiException::listingHasRequests();
        }

        if ($state !== ListingState::LIVE) {
            throw DomainApiException::listingNotLiveForPriceChange();
        }
    }

    public function rules(): array
    {
        $gold = $this->field() === ListingPriceField::MAKING_CHARGE_PER_G;

        return [
            'making_charge_per_g' => $gold ? ['required', ...ListingRules::MONEY, 'gte:0'] : ['prohibited'],
            'asking_price' => $gold ? ['prohibited'] : ['required', ...ListingRules::MONEY, 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $field = $this->field()->value;
            $current = $this->listing()->currentPriceFigure();

            if ($validator->errors()->has($field) || $current === null || ! is_numeric($this->input($field))) {
                return;
            }

            if (bccomp((string) $this->input($field), (string) $current, 4) === 0) {
                $validator->errors()->add($field, 'This is already the price.');
            }
        }];
    }
}
