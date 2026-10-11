<?php

namespace App\Support\Auth;

use App\Models\Staff;
use App\Models\StaffMfa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * A staff member's second factor: a TOTP code, accepted once per time-step, or a one-time
 * recovery code. Shared by the sign-in (`VerifyStaffMfaAction`) and the frozen founder's
 * tokenless unfreeze confirmation (spec 021 research R8), so both share the replay guard.
 */
final class StaffTotpVerifier
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /** A code is good once: the last accepted time-step is remembered and never accepted again. */
    public function verifyTotp(Staff $staff, string $code): bool
    {
        $replayKey = 'staff-mfa-last-timestep:'.$staff->staff_id;

        // The 0 seed matters: with a null "old" step the library answers `true`
        // instead of the matched time-step, and there would be nothing to remember.
        $timestep = $this->google2fa->verifyKeyNewer(
            $staff->mfa->mfa_secret_encrypted,
            $code,
            (int) Cache::get($replayKey, 0),
        );

        if (! is_int($timestep)) {
            return false;
        }

        Cache::put($replayKey, $timestep, now()->addMinutes(5));

        return true;
    }

    public function redeemRecoveryCode(Staff $staff, string $recoveryCode): bool
    {
        $recoveryCode = Str::lower(trim($recoveryCode));

        return DB::transaction(function () use ($staff, $recoveryCode) {
            $mfa = StaffMfa::query()->whereKey($staff->staff_id)->lockForUpdate()->firstOrFail();
            $hashes = $mfa->recovery_codes_hash ?? [];

            foreach ($hashes as $i => $hash) {
                if (Hash::check($recoveryCode, $hash)) {
                    unset($hashes[$i]);
                    $mfa->update(['recovery_codes_hash' => array_values($hashes)]);

                    return true;
                }
            }

            return false;
        });
    }
}
