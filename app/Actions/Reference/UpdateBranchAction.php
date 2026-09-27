<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Branch;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Edit a branch's fields and, when `hours` is sent, replace its whole week
 * (spec 004 FR-001..FR-004). Disabling is an edit: branches are never
 * deleted. Each part is audited with before and after, only when it changed.
 */
final class UpdateBranchAction
{
    use ReplacesBranchWeek;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data  validated UpdateBranchRequest data */
    public function handle(Staff $actor, int $branchId, array $data): Branch
    {
        return DB::transaction(function () use ($actor, $branchId, $data) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);

            $branch->fill(array_intersect_key($data, array_flip(Branch::EDITABLE)));
            $changed = array_keys($branch->getDirty());

            if ($changed !== []) {
                $before = array_intersect_key($branch->getOriginal(), array_flip($changed));
                $branch->save();

                $this->audit->execute(
                    AuditEvent::BRANCH_UPDATED,
                    'success',
                    ['branch_id' => $branch->branch_id, 'changes' => $branch->only($changed)],
                    entityType: 'branch',
                    actorStaffId: $actor->staff_id,
                    before: $before,
                );
            }

            if (array_key_exists('hours', $data)) {
                $before = self::currentWeek($branch);
                $after = $this->replaceWeek($branch, $data['hours']);

                if ($before !== $after) {
                    $this->audit->execute(
                        AuditEvent::BRANCH_HOURS_REPLACED,
                        'success',
                        ['branch_id' => $branch->branch_id, 'hours' => $after],
                        entityType: 'branch',
                        actorStaffId: $actor->staff_id,
                        before: ['hours' => $before],
                    );
                }
            }

            return $branch;
        });
    }
}
