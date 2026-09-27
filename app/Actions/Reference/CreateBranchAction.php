<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Branch;
use App\Models\Staff;
use App\Support\WorkingHours\PlatformCalendar;
use Illuminate\Support\Facades\DB;

/** Add an inspection branch with its weekly hours (spec 004 FR-001, FR-002). Audited. */
final class CreateBranchAction
{
    use ReplacesBranchWeek;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data  validated StoreBranchRequest data */
    public function handle(Staff $actor, array $data): Branch
    {
        return DB::transaction(function () use ($actor, $data) {
            $branch = Branch::query()->create([
                'name_en' => $data['name_en'],
                'name_ar' => $data['name_ar'],
                'address_en' => $data['address_en'],
                'address_ar' => $data['address_ar'],
                'timezone' => $data['timezone'] ?? PlatformCalendar::TIMEZONE,
                'is_enabled' => $data['is_enabled'] ?? true,
            ]);

            $week = $this->replaceWeek($branch, $data['hours']);

            // audit_log.entity_id is a UUID: the integer id goes in the payload (research R5).
            $this->audit->execute(
                AuditEvent::BRANCH_CREATED,
                'success',
                ['branch_id' => $branch->branch_id] + $branch->only(Branch::EDITABLE) + ['hours' => $week],
                entityType: 'branch',
                actorStaffId: $actor->staff_id,
            );

            return $branch;
        });
    }
}
