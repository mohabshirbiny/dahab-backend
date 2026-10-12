<?php

namespace App\Http\Requests\Customer\Promo;

use Illuminate\Foundation\Http\FormRequest;

/** `PUT /customer/me/listings/{listing}/promo`: a promo code or a referral code. */
class ApplyListingPromoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:64']];
    }
}
