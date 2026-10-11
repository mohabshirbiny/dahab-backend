<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Facades\DB;

/**
 * The held founder device polls its approval (spec 021 research R4). Public: the `approval_ref`
 * and the same `X-Device-Id` are the credentials. Under a row lock:
 *
 *  - pending → `{status: pending, expires_at}`;
 *  - refused → 403 `device_approval_refused`;
 *  - unknown, expired, already collected or another device → 401 `device_approval_invalid`;
 *  - approved → the account is re-checked (disabled → 401 `invalid_credentials`, frozen → 403
 *    `account_frozen`, nothing minted), the approval is consumed (single use, D21-4) and the
 *    sign-in completes with `via: device_approval`, which records the device as known.
 */
final class CollectDeviceApprovalAction
{
    public function __construct(
        private readonly CompleteStaffSignInAction $complete,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @return array{status: 'pending', expires_at: string}|array{status: 'approved', staff: Staff, session: SessionDto}
     */
    public function execute(string $approvalRef, RequestContext $ctx): array
    {
        return DB::transaction(function () use ($approvalRef, $ctx) {
            $approval = FounderDeviceApproval::query()
                ->where('ref_hash', FounderDeviceGate::refHash($approvalRef))
                ->lockForUpdate()
                ->first();

            if ($approval === null
                || $ctx->deviceFingerprintHash === null
                || ! hash_equals($approval->device_fingerprint, $ctx->deviceFingerprintHash)) {
                throw AuthApiException::deviceApprovalInvalid();
            }

            if ($approval->refused_at !== null) {
                throw AuthApiException::deviceApprovalRefused();
            }

            if ($approval->consumed_at !== null || now()->gte($approval->expires_at)) {
                throw AuthApiException::deviceApprovalInvalid();
            }

            if ($approval->approved_at === null) {
                return ['status' => FounderDeviceApproval::STATUS_PENDING, 'expires_at' => $approval->expires_at->toIso8601String()];
            }

            $staff = Staff::query()->lockForUpdate()->find($approval->staff_id);
            if ($staff === null || ! $staff->is_active) {
                throw AuthApiException::invalidCredentials();
            }
            if ($staff->activeFreeze()->exists()) {
                throw AuthApiException::accountFrozen();
            }

            $approval->update(['consumed_at' => now()]);

            $this->audit->execute(
                AuditEvent::FOUNDER_DEVICE_APPROVAL_COLLECTED,
                'success',
                ['approval_id' => $approval->approval_id, 'device_ref' => $approval->deviceRef()],
                'staff',
                $staff->staff_id,
                $ctx->withStaff($staff->staff_id),
            );

            return [
                'status' => FounderDeviceApproval::STATUS_APPROVED,
                'staff' => $staff,
                'session' => $this->complete->execute($staff, $ctx, 'device_approval'),
            ];
        });
    }
}
