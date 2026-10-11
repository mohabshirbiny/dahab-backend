<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 021 Story 1 (research R1–R5): after the password and MFA, a founder on a device not yet
 * known for them gets no token. The sign-in is held as a `founder_device_approval` row until the
 * other founder approves it; the held device polls with the opaque `approval_ref` it receives.
 *
 * Called by the three paths that end in CompleteStaffSignInAction (login without an MFA step,
 * MFA verify, MFA enroll), just before it. Returns null to let the sign-in complete, or the held
 * answer (R18). Non-founders, known devices and `founder_device_approval_enforced = false` pass.
 */
final class FounderDeviceGate
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{device_approval_required: true, approval_id: string, approval_ref: string, expires_at: string, poll_interval_seconds: int}|null */
    public function hold(Staff $staff, RequestContext $ctx): ?array
    {
        if (! $staff->is_founder || ! config('dahab-auth.founder_device_approval_enforced')) {
            return null;
        }

        // R3: the approval needs a fingerprint, and without one the device could never be known.
        if ($ctx->deviceFingerprintHash === null) {
            throw AuthApiException::deviceIdRequired();
        }

        if ($staff->isKnownDevice($ctx->deviceFingerprintHash)) {
            return null;
        }

        $ref = self::newRef();
        $platform = request()->header('X-Device-Platform', 'web');

        $approval = DB::transaction(function () use ($staff, $ctx, $ref, $platform) {
            // R5: one open request per founder device; an earlier one ends now.
            FounderDeviceApproval::query()
                ->where('staff_id', $staff->staff_id)
                ->where('device_fingerprint', $ctx->deviceFingerprintHash)
                ->pending()
                ->lockForUpdate()
                ->get()
                ->each(fn (FounderDeviceApproval $old) => $old->update(['expires_at' => now()]));

            $approval = FounderDeviceApproval::query()->create([
                'staff_id' => $staff->staff_id,
                'device_fingerprint' => $ctx->deviceFingerprintHash,
                'requested_at' => now(),
                'expires_at' => now()->addMinutes((int) config('dahab-auth.founder_device_approval.ttl_minutes')),
                'ref_hash' => self::refHash($ref),
                'platform' => is_string($platform) ? Str::limit($platform, 40, '') : 'web',
                'user_agent' => $ctx->userAgent !== null ? Str::limit($ctx->userAgent, 255, '') : null,
                'ip_address' => $ctx->ip !== '' ? $ctx->ip : null,
            ]);

            $this->audit->execute(
                AuditEvent::FOUNDER_DEVICE_APPROVAL_REQUESTED,
                'success',
                ['approval_id' => $approval->approval_id, 'platform' => $approval->platform, 'device_ref' => $approval->deviceRef()],
                'staff',
                $staff->staff_id,
                $ctx->withStaff($staff->staff_id),
            );

            return $approval;
        });

        return [
            'device_approval_required' => true,
            'approval_id' => $approval->approval_id,
            'approval_ref' => $ref,
            'expires_at' => $approval->expires_at->toIso8601String(),
            'poll_interval_seconds' => (int) config('dahab-auth.founder_device_approval.poll_interval_seconds'),
        ];
    }

    /** The HMAC stored for a poll ref (the spec 013/017 link pattern); the ref itself is never stored. */
    public static function refHash(string $ref): string
    {
        return hash_hmac('sha256', 'founder-device-approval:'.$ref, (string) config('app.key'));
    }

    /** 32 random bytes, base64url. */
    private static function newRef(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
