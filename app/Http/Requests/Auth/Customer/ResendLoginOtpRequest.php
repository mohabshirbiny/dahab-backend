<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Ask for a fresh SMS code on a pending new-device sign-in. */
#[OA\Schema(
    schema: 'ResendLoginOtpRequest',
    required: ['challenge_id'],
    properties: [
        new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
    ],
)]
class ResendLoginOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'uuid'],
        ];
    }
}
