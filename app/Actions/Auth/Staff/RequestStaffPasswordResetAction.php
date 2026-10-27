<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * "Forgot password?" for a staff member (spec 020 US2, research R3).
 *
 * The answer is identical for an unknown address, a disabled member, the system actor and an
 * active member; only an active, non-system member gets the mail and an audit row. A frozen
 * member is sent the link too (they still cannot sign in while frozen).
 */
final class RequestStaffPasswordResetAction
{
    public function __construct(
        private readonly IssueStaffPasswordLinkAction $links,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function execute(string $email, RequestContext $ctx): void
    {
        $staff = Staff::query()->where('email', $email)->whereNull('deleted_at')->first();

        if ($staff === null || $staff->is_system || ! $staff->is_active) {
            // Equal work for the caller; nothing is sent and nothing says why.
            Hash::make('staff-password-reset-dummy');
            Log::info('staff_password_reset.requested_for_unusable_address');

            return;
        }

        DB::transaction(function () use ($staff, $ctx) {
            $link = $this->links->handle($staff, IssueStaffPasswordLinkAction::RECOVERY);

            $this->audit->execute(
                AuditEvent::STAFF_PASSWORD_RESET_REQUESTED,
                'success',
                ['expires_at' => $link['expires_at']->toIso8601String()],
                'staff',
                $staff->staff_id,
                $ctx->withStaff($staff->staff_id),
                actorStaffId: $staff->staff_id,
            );
        });
    }
}
