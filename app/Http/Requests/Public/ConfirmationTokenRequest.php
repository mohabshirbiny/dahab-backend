<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'WithdrawalConfirmationTokenRequest',
    required: ['token'],
    properties: [new OA\Property(property: 'token', type: 'string', description: 'The token from the email link')],
)]
class ConfirmationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'min:20', 'max:100']];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
