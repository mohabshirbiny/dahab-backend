<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\BranchClosure;
use App\Models\Staff;
use App\Support\WorkingHours\PlatformCalendar;
use Illuminate\Support\Facades\DB;

/**
 * Remove a future closure (spec 004 edge case). A closure dated today or
 * earlier already shaped deadlines that were counted, so it stays
 * (409 closure_in_past).
 */
final class RemoveBranchClosureAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, int $closureId): void
    {
        DB::transaction(function () use ($actor, $closureId) {
            $closure = BranchClosure::query()->lockForUpdate()->findOrFail($closureId);

            if ($closure->closure_date->toDateString() <= PlatformCalendar::todayFor($closure->branch_id)) {
                throw DomainApiException::closureInPast();
            }

            $before = AddBranchClosureAction::snapshot($closure);
            $closure->delete();

            $this->audit->execute(
                AuditEvent::CLOSURE_REMOVED,
                'success',
                ['closure_id' => $before['closure_id']],
                entityType: 'branch_closure',
                actorStaffId: $actor->staff_id,
                before: $before,
            );
        });
    }
}
