<?php

namespace App\Actions\FounderSecurity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\StaffPermission;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\AccountFreeze;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * One unfreeze confirmation from a staff session (spec 021 Story 2, research R8, C1). The slot
 * is the server's decision, never the client's:
 *
 *  - a founder who is not frozen → slot 2, always last: it needs slot 1 (409 `unfreeze_order`)
 *    from someone else (409 `unfreeze_already_confirmed`), and it unfreezes the account; no
 *    session comes back (they were deleted at freeze time);
 *  - otherwise a holder of `founder.unfreeze_second` → slot 1 as `second_approver`
 *    (409 `unfreeze_already_confirmed` when taken);
 *  - anyone else → 403 `permission_denied`.
 *
 * The frozen founder's own slot 1 is the tokenless ConfirmFrozenFounderUnfreezeAction.
 */
final class ConfirmUnfreezeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $freezeId, ?string $note): AccountFreeze
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return DB::transaction(function () use ($actor, $freezeId, $note) {
            FreezeFounderAction::lock();

            $freeze = AccountFreeze::query()->lockForUpdate()->findOrFail($freezeId);

            if (! $freeze->isOpen()) {
                throw DomainApiException::notFrozen();
            }
            if ($freeze->frozen_staff_id === $actor->staff_id) {
                throw DomainApiException::founderSelfAction();
            }
            if ($actor->activeFreeze()->exists()) {
                throw AuthApiException::accountFrozen();
            }

            if ($actor->is_founder) {
                if ($freeze->unfreeze_confirm_1 === null) {
                    throw DomainApiException::unfreezeOrder();
                }
                if ($freeze->unfreeze_confirm_1 === $actor->staff_id) {
                    throw DomainApiException::unfreezeAlreadyConfirmed();
                }

                $freeze->update([
                    'unfreeze_confirm_2' => $actor->staff_id,
                    'unfreeze_confirm_2_at' => now(),
                    'unfrozen_at' => now(),
                ]);

                $this->record($actor, $freeze, 2, 'founder', $note);
                $this->audit->execute(
                    AuditEvent::FOUNDER_UNFROZEN,
                    'success',
                    ['freeze_id' => $freeze->freeze_id],
                    entityType: 'staff',
                    entityId: $freeze->frozen_staff_id,
                    actorStaffId: $actor->staff_id,
                    reason: $note,
                );
            } elseif ($actor->can(StaffPermission::FOUNDER_UNFREEZE_SECOND->value)) {
                if ($freeze->unfreeze_confirm_1 !== null) {
                    throw DomainApiException::unfreezeAlreadyConfirmed();
                }

                $freeze->update([
                    'unfreeze_confirm_1' => $actor->staff_id,
                    'unfreeze_confirm_1_at' => now(),
                    'unfreeze_confirm_1_kind' => AccountFreeze::KIND_SECOND_APPROVER,
                ]);

                $this->record($actor, $freeze, 1, AccountFreeze::KIND_SECOND_APPROVER, $note);
            } else {
                throw AuthApiException::permissionDenied();
            }

            return $freeze->load(['frozenStaff', 'frozenBy', 'confirmer1', 'confirmer2']);
        });
    }

    private function record(Staff $actor, AccountFreeze $freeze, int $slot, string $kind, ?string $note): void
    {
        $this->audit->execute(
            AuditEvent::FOUNDER_UNFREEZE_CONFIRMED,
            'success',
            ['freeze_id' => $freeze->freeze_id, 'slot' => $slot, 'kind' => $kind],
            entityType: 'staff',
            entityId: $freeze->frozen_staff_id,
            actorStaffId: $actor->staff_id,
            reason: $note,
        );
    }
}
