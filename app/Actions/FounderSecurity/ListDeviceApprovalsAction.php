<?php

namespace App\Actions\FounderSecurity;

use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Every founder's held device requests, newest first (spec 021 contracts §2): both founders see
 * the same history. `pendingForMe` counts the requests the viewer may decide.
 */
final class ListDeviceApprovalsAction
{
    public function handle(string $status, int $perPage): LengthAwarePaginator
    {
        return FounderDeviceApproval::query()
            ->with(['staff', 'approver', 'refuser'])
            ->when($status !== 'all', fn ($q) => $q->withStatus($status))
            ->orderByDesc('requested_at')
            ->orderByDesc('approval_id')
            ->paginate($perPage);
    }

    public function pendingFor(Staff $viewer): int
    {
        if ($viewer->activeFreeze()->exists()) {
            return 0;
        }

        return FounderDeviceApproval::query()->pending()->forApprover($viewer)->count();
    }

    /** True when the viewer may approve or refuse this request now. */
    public static function canDecide(FounderDeviceApproval $approval, ?Staff $viewer, bool $viewerFrozen): bool
    {
        return $viewer !== null
            && $viewer->is_founder
            && ! $viewerFrozen
            && $approval->staff_id !== $viewer->staff_id
            && $approval->isPending();
    }
}
