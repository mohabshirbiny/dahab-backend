<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Models\StaffDeviceFingerprint;
use App\Support\RequestContext;
use App\Support\SessionDto;

/**
 * The last step of every successful staff sign-in (password only, or password
 * + TOTP): remember the device, mint the token family, audit the sign-in.
 */
final class CompleteStaffSignInAction
{
    public function __construct(
        private readonly IssueTokenFamilyAction $issueTokens,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @param 'password'|'mfa'|'mfa_enrollment' $via */
    public function execute(Staff $staff, RequestContext $ctx, string $via): SessionDto
    {
        if ($ctx->deviceFingerprintHash !== null) {
            StaffDeviceFingerprint::query()->updateOrCreate(
                ['staff_id' => $staff->staff_id, 'fingerprint_hash' => $ctx->deviceFingerprintHash],
                ['first_seen_at' => now(), 'last_seen_at' => now()],
            );
        }

        $session = $this->issueTokens->forStaff($staff);

        $this->audit->execute(
            AuditEvent::STAFF_SIGN_IN,
            'success',
            ['via' => $via, 'roles' => $staff->getRoleNames()->sort()->values()->all()],
            'staff',
            $staff->staff_id,
            $ctx->withStaff($staff->staff_id),
        );

        return $session;
    }
}
