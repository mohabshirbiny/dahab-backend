<?php

namespace App\Http\Requests\Customer\BuyRequest;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * The seller answers the head of the queue (spec 011 FR-014, FR-015):
 * `buy_request_id` always; `branch_id` to accept (one of the listing's
 * enabled branch options).
 */
#[OA\Schema(
    schema: 'AcceptBuyRequestRequest',
    required: ['buy_request_id', 'branch_id'],
    properties: [
        new OA\Property(property: 'buy_request_id', type: 'string', format: 'uuid', description: 'Must be the head of the queue'),
        new OA\Property(property: 'branch_id', type: 'integer', example: 3, description: 'One of the listing\'s branch options, still enabled'),
    ],
)]
#[OA\Schema(
    schema: 'DeclineBuyRequestRequest',
    required: ['buy_request_id'],
    properties: [
        new OA\Property(property: 'buy_request_id', type: 'string', format: 'uuid', description: 'Must be the head of the queue'),
    ],
)]
class AnswerBuyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['buy_request_id' => ['required', 'uuid']];

        if ($this->routeIs('api.v1.customer.me.listings.accept')) {
            $rules['branch_id'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
