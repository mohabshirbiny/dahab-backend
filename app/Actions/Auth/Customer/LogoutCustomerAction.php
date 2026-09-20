<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\RevokeTokenFamilyAction;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;

final class LogoutCustomerAction
{
    public function __construct(private readonly RevokeTokenFamilyAction $revoke) {}

    public function current(Customer $customer, $currentToken): void
    {
        if ($currentToken === null) {
            return;
        }

        $familyId = $currentToken->family_id ?? null;
        if ($familyId !== null) {
            $this->revoke->byFamily($familyId);
        } elseif (isset($currentToken->id)) {
            $this->revoke->byTokenId($currentToken->id);
        }

        Auth::forgetGuards();
    }

    public function all(Customer $customer): void
    {
        $this->revoke->forActor(Customer::class, $customer->customer_id);
        Auth::forgetGuards();
    }
}
