<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RevokeTokenFamilyAction;
use App\Models\Staff;
use Illuminate\Support\Facades\Auth;

final class LogoutStaffAction
{
    public function __construct(private readonly RevokeTokenFamilyAction $revoke) {}

    public function current(Staff $staff, $currentToken): void
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

    public function all(Staff $staff): void
    {
        $this->revoke->forActor(Staff::class, $staff->staff_id);
        Auth::forgetGuards();
    }
}
