<?php

namespace App\Actions\FounderSecurity;

use App\Enums\StaffPermission;
use App\Models\AccountFreeze;
use App\Models\Staff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Freezes with their confirmations (spec 021 contracts §3), newest first; `open` (default) or
 * `all`. For the viewer: `my_slot` — 2 for a founder who is not frozen, 1 for a holder of
 * `founder.unfreeze_second`, else null — and `can_confirm` — that slot is free and in order.
 */
final class ListFreezesAction
{
    public function handle(string $state, int $perPage): LengthAwarePaginator
    {
        return AccountFreeze::query()
            ->with(['frozenStaff', 'frozenBy', 'confirmer1', 'confirmer2'])
            ->when($state === 'open', fn ($q) => $q->whereNull('unfrozen_at'))
            ->orderByDesc('frozen_at')
            ->orderByDesc('freeze_id')
            ->paginate($perPage);
    }

    /** @return 1|2|null */
    public static function slotFor(AccountFreeze $freeze, ?Staff $viewer): ?int
    {
        if ($viewer === null || $viewer->staff_id === $freeze->frozen_staff_id) {
            return null;
        }
        if ($viewer->is_founder) {
            return 2;
        }

        return $viewer->can(StaffPermission::FOUNDER_UNFREEZE_SECOND->value) ? 1 : null;
    }

    public static function canConfirm(AccountFreeze $freeze, ?Staff $viewer): bool
    {
        if (! $freeze->isOpen()) {
            return false;
        }

        return match (self::slotFor($freeze, $viewer)) {
            2 => $freeze->unfreeze_confirm_1 !== null && $freeze->unfreeze_confirm_1 !== $viewer?->staff_id,
            1 => $freeze->unfreeze_confirm_1 === null,
            default => false,
        };
    }
}
