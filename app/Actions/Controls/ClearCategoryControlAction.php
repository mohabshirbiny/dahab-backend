<?php

namespace App\Actions\Controls;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\ControlLevel;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\CategoryControl;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Switch a control off (spec 019 US7, FR-072). The row is kept, with who cleared it and when;
 * nothing is deleted. Clearing the stop of everything clears every category's row at once and
 * emails the founders (D5). Pieces hidden by a pause return by themselves: the market read
 * simply stops leaving them out. Nothing that was refused during the pause is restored (D4).
 */
final class ClearCategoryControlAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return int how many rows were cleared */
    public function handle(Staff $actor, ControlLevel $level, ?PieceCategory $category, ?RequestContext $ctx = null): int
    {
        if ($level !== ControlLevel::STOP_EVERYTHING && $category === null) {
            throw DomainApiException::controlCategoryRequired();
        }

        $count = DB::transaction(function () use ($actor, $level, $category, $ctx) {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('category_control'))");

            $rows = CategoryControl::query()
                ->where('is_active', true)->where('level', $level->value)
                ->when($level !== ControlLevel::STOP_EVERYTHING, fn ($q) => $q->where('category', $category->value))
                ->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw DomainApiException::controlNotActive();
            }

            foreach ($rows as $row) {
                $row->forceFill(['is_active' => false, 'cleared_by' => $actor->staff_id, 'cleared_at' => now()])->save();
            }

            $this->audit->execute(
                AuditEvent::CONTROL_CLEARED,
                'success',
                [
                    'level' => $level->value,
                    'categories' => $rows->map(fn (CategoryControl $r) => $r->category->value)->values()->all(),
                ],
                entityType: 'category_control',
                entityId: $rows->first()->control_id,
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return $rows->count();
        });

        if ($level === ControlLevel::STOP_EVERYTHING) {
            SetCategoryControlAction::alertFounders(false, $actor->full_name, 'Started again from Switches.');
        }

        return $count;
    }
}
