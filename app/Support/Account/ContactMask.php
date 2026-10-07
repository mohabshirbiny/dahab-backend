<?php

namespace App\Support\Account;

use App\Http\Resources\Customer\WithdrawalConfirmationResource;
use App\Http\Resources\Staff\ListingResource;

/** The one way a phone number or an email is shown in a notice or an audit row (spec 017 FR-013). */
final class ContactMask
{
    public static function phone(?string $phone): ?string
    {
        return $phone === null ? null : ListingResource::maskPhone($phone);
    }

    public static function email(?string $email): ?string
    {
        return $email === null ? null : WithdrawalConfirmationResource::maskEmail($email);
    }
}
