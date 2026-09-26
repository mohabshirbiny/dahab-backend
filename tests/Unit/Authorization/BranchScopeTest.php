<?php

use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Support\Authorization\BranchScope;

// Spec 002 FR-041 / research R12. No catalogue code is branch-scoped yet, so
// the rule is exercised through the explicit `$scoped` seam.

function staffAt(?int $branchId): Staff
{
    return (new Staff)->forceFill(['branch_id' => $branchId]);
}

it('allows a branch-scoped action on the staff member\'s own branch', function () {
    BranchScope::check(staffAt(3), scoped: true, recordBranchId: 3);
})->throwsNoExceptions();

it('refuses a branch-scoped action on another branch', function () {
    BranchScope::check(staffAt(3), scoped: true, recordBranchId: 4);
})->throws(DomainApiException::class, 'This record belongs to another branch.');

it('refuses a branch-scoped action when the staff member has no branch', function () {
    BranchScope::check(staffAt(null), scoped: true, recordBranchId: 4);
})->throws(DomainApiException::class);

it('ignores branches for permissions that are not branch-scoped', function () {
    BranchScope::check(staffAt(null), scoped: false, recordBranchId: 4);
    BranchScope::check(staffAt(3), scoped: false, recordBranchId: 4);
})->throwsNoExceptions();
