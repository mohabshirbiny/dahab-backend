<?php

namespace App\Actions\Auth\Staff;

use App\Models\Staff;
use App\Notifications\StaffPasswordLinkNotification;
use App\Support\Withdrawals\ConfirmationTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Issues the single-use link a staff member sets a password through (spec 020 R3).
 *
 * One `one_time_token` row (purpose `password_reset_staff`), only the HMAC of the secret
 * stored. The member's older open links are voided, so the last one issued is the only one
 * that works. `origin` says why: `recovery` (Forgot password?, 30 minutes), `invitation`
 * (a new member) or `enabled` (a re-enabled member), the last two 48 hours.
 * The mail goes out after the caller's transaction commits.
 */
final class IssueStaffPasswordLinkAction
{
    public const RECOVERY = 'recovery';

    public const INVITATION = 'invitation';

    public const ENABLED = 'enabled';

    /** @return array{link: string, expires_at: Carbon} */
    public function handle(Staff $staff, string $origin): array
    {
        $token = ConfirmationTokens::generate();
        $expiresAt = $origin === self::RECOVERY
            ? now()->addMinutes((int) config('dahab-auth.password_reset.staff_link.recovery_minutes'))
            : now()->addHours((int) config('dahab-auth.password_reset.staff_link.invitation_hours'));

        DB::transaction(function () use ($staff, $origin, $token, $expiresAt) {
            self::voidOpenLinks($staff->staff_id);

            DB::table('one_time_token')->insert([
                'token_hash' => ConfirmationTokens::hash($token),
                'purpose' => 'password_reset_staff',
                'actor_staff_id' => $staff->staff_id,
                'payload' => json_encode(['origin' => $origin], JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
            ]);
        });

        $link = rtrim((string) config('dahab-auth.password_reset.staff_link.url'), '/').'?token='.$token;
        $notice = new StaffPasswordLinkNotification($link, $origin, $expiresAt->toIso8601String());
        $email = $staff->email;

        DB::afterCommit(fn () => Notification::route('mail', $email)->notify($notice));

        return ['link' => $link, 'expires_at' => $expiresAt];
    }

    /** Consumes every open link of the member (a newer link, a disable, a reset). */
    public static function voidOpenLinks(string $staffId): int
    {
        return DB::table('one_time_token')
            ->where('actor_staff_id', $staffId)
            ->where('purpose', 'password_reset_staff')
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
