<?php

namespace App\Actions\FounderSecurity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\AccountFreeze;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use Illuminate\Support\Facades\DB;

/**
 * A founder freezes the other founder (spec 021 Story 2, research R7). Serialised by the
 * `dahab.founder_security` advisory lock plus row locks on both staff rows in id order, so of two
 * crossing freezes the second finds its own actor frozen (403 `account_frozen`): both founders
 * can never be frozen through the system. Every token of the target is deleted at once and its
 * pending device approvals end. A reason is required; it is stored and is the audit reason.
 */
final class FreezeFounderAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{freeze: AccountFreeze, sessions_ended: int, pending_approvals_ended: int} */
    public function handle(Staff $actor, string $targetId, ?string $reason): array
    {
        ReasonRule::assertPresent($reason);
        $reason = trim((string) $reason);

        return DB::transaction(function () use ($actor, $targetId, $reason) {
            self::lock();

            if ($targetId === $actor->staff_id) {
                throw DomainApiException::founderSelfAction();
            }

            $rows = Staff::query()->manageable()->whereIn('staff_id', [$actor->staff_id, $targetId])
                ->orderBy('staff_id')->lockForUpdate()->get()->keyBy('staff_id');

            if ($rows->get($actor->staff_id)?->activeFreeze()->exists() ?? true) {
                throw AuthApiException::accountFrozen();
            }

            $target = $rows->get($targetId);
            if ($target === null || ! $target->is_founder || ! $target->is_active) {
                throw DomainApiException::freezeTargetInvalid();
            }
            if ($target->activeFreeze()->exists()) {
                throw DomainApiException::alreadyFrozen();
            }

            $freeze = AccountFreeze::query()->create([
                'frozen_staff_id' => $target->staff_id,
                'frozen_by' => $actor->staff_id,
                'frozen_at' => now(),
                'reason' => $reason,
            ]);

            $sessions = $target->tokens()->delete();
            $approvals = FounderDeviceApproval::query()->where('staff_id', $target->staff_id)->pending()->update(['expires_at' => now()]);

            $this->audit->execute(
                AuditEvent::FOUNDER_FROZEN,
                'success',
                ['freeze_id' => $freeze->freeze_id, 'sessions_ended' => $sessions, 'pending_approvals_ended' => $approvals],
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                reason: $reason,
            );

            return [
                'freeze' => $freeze->load(['frozenStaff', 'frozenBy', 'confirmer1', 'confirmer2']),
                'sessions_ended' => $sessions,
                'pending_approvals_ended' => $approvals,
            ];
        });
    }

    /** Freezes and unfreeze confirmations run one at a time. */
    public static function lock(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('dahab.founder_security'))");
        }
    }
}
