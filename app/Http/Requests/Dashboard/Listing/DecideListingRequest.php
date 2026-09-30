<?php

namespace App\Http\Requests\Dashboard\Listing;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * The text a staff decision on a listing needs (spec 010 FR-029–FR-030a):
 * `message` when asking the seller for changes, `reason` when rejecting or
 * taking a listing down. The seller reads it.
 */
#[OA\Schema(
    schema: 'RequestListingChangesRequest',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', minLength: 10, maxLength: 1000, example: 'The hallmark photo is blurred and we cannot read the karat. Please retake it in daylight.', description: 'Goes to the seller'),
    ],
)]
#[OA\Schema(
    schema: 'ListingReasonRequest',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000, example: 'The photos are taken from another website.', description: 'Goes to the seller'),
    ],
)]
class DecideListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            $this->field() => [
                'required', 'string',
                'min:'.config('dahab-listings.decision_note_min'),
                'max:'.config('dahab-listings.decision_note_max'),
            ],
        ];
    }

    public function note(): string
    {
        return trim((string) $this->validated($this->field()));
    }

    /** `message` on request-changes, `reason` on reject and takedown. */
    private function field(): string
    {
        return str_ends_with((string) $this->route()?->getName(), '.request-changes') ? 'message' : 'reason';
    }
}
