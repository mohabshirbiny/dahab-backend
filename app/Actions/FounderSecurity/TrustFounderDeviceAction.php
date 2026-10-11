<?php

namespace App\Actions\FounderSecurity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Models\StaffDeviceFingerprint;
use App\Support\Auth\DeviceFingerprint;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Spec 021 C2 / research R10: trusts a founder's device from the server (`founder:trust-device`),
 * never over HTTP. It bootstraps the first device of each founder and is the way back when both
 * founders lost every known device. Refuses a non-founder, a disabled member or a frozen founder.
 * Upserts the fingerprint (keeping "known since"), ends any pending request for that device and
 * is audited as the system actor with the founder as entity; the founders are told (R11).
 */
final class TrustFounderDeviceAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{staff: Staff, device_ref: string, pending_ended: int} */
    public function handle(string $email, string $deviceId, string $platform = 'web'): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException('The device id is empty.');
        }

        return DB::transaction(function () use ($email, $deviceId, $platform) {
            $staff = Staff::query()->manageable()->where('email', $email)->lockForUpdate()->first();

            if ($staff === null || ! $staff->is_founder) {
                throw new InvalidArgumentException('No founder has this email.');
            }
            if (! $staff->is_active) {
                throw new InvalidArgumentException('This founder is disabled.');
            }
            if ($staff->activeFreeze()->exists()) {
                throw new InvalidArgumentException('This founder is frozen; unfreeze first.');
            }

            $hash = DeviceFingerprint::hash($deviceId, $platform);

            $device = StaffDeviceFingerprint::query()->firstOrNew(
                ['staff_id' => $staff->staff_id, 'fingerprint_hash' => $hash],
                ['first_seen_at' => now()],
            );
            $device->last_seen_at = now();
            $device->save();

            $ended = FounderDeviceApproval::query()
                ->where('staff_id', $staff->staff_id)
                ->where('device_fingerprint', $hash)
                ->pending()
                ->update(['expires_at' => now()]);

            $deviceRef = substr($hash, 0, 8);

            $this->audit->execute(
                AuditEvent::FOUNDER_DEVICE_TRUSTED_BY_CONSOLE,
                'success',
                ['device_ref' => $deviceRef, 'platform' => $platform, 'pending_ended' => $ended],
                'staff',
                $staff->staff_id,
                RequestContext::forSystem(),
            );

            return ['staff' => $staff, 'device_ref' => $deviceRef, 'pending_ended' => $ended];
        });
    }
}
