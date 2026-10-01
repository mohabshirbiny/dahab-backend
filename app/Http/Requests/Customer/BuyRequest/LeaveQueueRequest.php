<?php

namespace App\Http\Requests\Customer\BuyRequest;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Leave the queue (spec 011 FR-010, FR-011). */
#[OA\Schema(
    schema: 'LeaveQueueRequest',
    properties: [
        new OA\Property(property: 'notify_when_free', type: 'boolean', default: false, description: 'Tell me once if the piece returns to the market with nobody in line'),
    ],
)]
class LeaveQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['notify_when_free' => ['sometimes', 'boolean']];
    }

    public function notifyWhenFree(): bool
    {
        return (bool) $this->validated('notify_when_free', false);
    }
}
