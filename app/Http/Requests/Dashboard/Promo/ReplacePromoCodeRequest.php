<?php

namespace App\Http\Requests\Dashboard\Promo;

/**
 * Editing a code nobody has used yet (owner request 2026-10-26): the New-code body without `code`,
 * which is in the path and never changes. Every term is sent again, as on the form.
 */
class ReplacePromoCodeRequest extends CreatePromoCodeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['code']);

        return $rules;
    }
}
