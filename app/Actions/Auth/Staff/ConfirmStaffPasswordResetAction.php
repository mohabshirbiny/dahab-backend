<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\StaffPassword;
use App\Support\RequestContext;
use App\Support\Withdrawals\ConfirmationTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sets a staff member's password through a link (spec 020 US2, research R3, R4).
 *
 * The link is spent with one atomic UPDATE, so two simultaneous uses cannot both win.
 * Unknown, used, voided, expired or disabled-member links all answer `reset_link_invalid`.
 * The password row is created or updated and nothing else of the member's security changes:
 * `force_reenroll_mfa_at` and `staff_mfa` stay as they are, so the next sign-in still asks
 * for the authenticator (R4). Every staff token is deleted and the sign-in lockout cleared.
 */
final class ConfirmStaffPasswordResetAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function execute(string $token, string $password, RequestContext $ctx): void
    {
        DB::transaction(function () use ($token, $password, $ctx) {
            // The application's clock, not the database's, so expiry follows `now()` everywhere.
            $now = now();
            $row = DB::selectOne(
                "UPDATE one_time_token SET consumed_at = ?
                  WHERE token_hash = ? AND purpose = 'password_reset_staff'
                    AND consumed_at IS NULL AND expires_at > ?
              RETURNING actor_staff_id, payload",
                [$now, ConfirmationTokens::hash($token), $now],
            );

            $staff = $row === null ? null : Staff::query()->whereKey($row->actor_staff_id)->lockForUpdate()->first();

            if ($staff === null || $staff->is_system || ! $staff->is_active) {
                throw DomainApiException::resetLinkInvalid();
            }

            $origin = json_decode((string) $row->payload, true)['origin'] ?? IssueStaffPasswordLinkAction::RECOVERY;

            $existing = StaffPassword::query()->whereKey($staff->staff_id)->first();
            if ($existing === null) {
                StaffPassword::query()->create([
                    'staff_id' => $staff->staff_id,
                    'password_hash' => Hash::make($password),
                    'password_changed_at' => now(),
                ]);
            } else {
                // Only the hash and its date: the MFA re-enrolment flag is left alone.
                $existing->forceFill(['password_hash' => Hash::make($password), 'password_changed_at' => now()])->save();
            }

            IssueStaffPasswordLinkAction::voidOpenLinks($staff->staff_id);
            $ended = $staff->tokens()->delete();

            RateLimiter::clear(md5('auth.staff.login'.'staff-login-id:'.mb_strtolower(trim($staff->email))));

            $this->audit->execute(
                AuditEvent::STAFF_PASSWORD_RESET_COMPLETED,
                'success',
                ['origin' => $origin, 'sessions_ended' => $ended],
                'staff',
                $staff->staff_id,
                $ctx->withStaff($staff->staff_id),
                actorStaffId: $staff->staff_id,
            );
        });
    }
}
