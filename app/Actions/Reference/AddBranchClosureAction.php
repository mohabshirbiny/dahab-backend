<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\BranchClosure;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Close one branch, or every branch (`branch_id` null), for a whole day
 * (spec 004 FR-003). The unique key treats NULLs as distinct, so the
 * duplicate check runs here, serialized by an advisory lock (data-model).
 */
final class AddBranchClosureAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  array{branch_id: int|null, closure_date: string, reason_en?: string|null, reason_ar?: string|null}  $data */
    public function handle(Staff $actor, array $data): BranchClosure
    {
        return DB::transaction(function () use ($actor, $data) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('dahab.branch_closure'))");
            }

            $exists = BranchClosure::query()
                ->where('closure_date', $data['closure_date'])
                ->when(
                    $data['branch_id'] === null,
                    fn ($q) => $q->whereNull('branch_id'),
                    fn ($q) => $q->where('branch_id', $data['branch_id']),
                )
                ->exists();

            if ($exists) {
                throw DomainApiException::closureExists();
            }

            $closure = BranchClosure::query()->create([
                'branch_id' => $data['branch_id'],
                'closure_date' => $data['closure_date'],
                'reason_en' => $data['reason_en'] ?? null,
                'reason_ar' => $data['reason_ar'] ?? null,
            ]);

            $this->audit->execute(
                AuditEvent::CLOSURE_ADDED,
                'success',
                self::snapshot($closure),
                entityType: 'branch_closure',
                actorStaffId: $actor->staff_id,
            );

            return $closure;
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(BranchClosure $closure): array
    {
        return [
            'closure_id' => $closure->closure_id,
            'branch_id' => $closure->branch_id,
            'closure_date' => $closure->closure_date->toDateString(),
            'reason_en' => $closure->reason_en,
            'reason_ar' => $closure->reason_ar,
        ];
    }
}
