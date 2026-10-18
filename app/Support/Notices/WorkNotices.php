<?php

namespace App\Support\Notices;

use App\Enums\StaffNoticeKind;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec 026: Dashboard work notices — the bell tells the staff who work a queue that a customer
 * created work for it. Called inside the customer action's transaction; the notice is written
 * after commit (nothing if the change rolls back) in the same request, never through the queue,
 * so it works on a server without a queue worker (FR-004). Recipients: active staff, not the
 * system actor, not frozen, holding the kind's permission, and for orders assigned to the
 * order's branch (D26-3). While unread, one notice per staff member and queue counts up
 * (D26-2, research R2). A failure is reported and swallowed: it never fails the customer's request.
 */
final class WorkNotices
{
    public static function record(StaffNoticeKind $kind, ?string $itemId = null, ?string $ref = null, ?int $branchId = null): void
    {
        DB::afterCommit(function () use ($kind, $itemId, $ref, $branchId) {
            try {
                self::write($kind, $itemId, $ref, $branchId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private static function write(StaffNoticeKind $kind, ?string $itemId, ?string $ref, ?int $branchId): void
    {
        $permission = $kind->permission();
        if ($permission === null || ($kind->branchScoped() && $branchId === null)) {
            return;
        }

        $groupKey = $kind->branchScoped() ? $kind->value.':'.$branchId : $kind->value;

        foreach (self::recipients($permission->value, $kind->branchScoped() ? $branchId : null) as $staffId) {
            self::bump($staffId, $kind, $groupKey, $itemId, $ref);
        }
    }

    /** @return list<string> staff ids */
    private static function recipients(string $permission, ?int $branchId): array
    {
        return Staff::query()
            ->manageable()
            ->where('is_active', true)
            ->whereDoesntHave('activeFreeze')
            ->where(fn (Builder $q) => $q
                ->whereHas('roles.permissions', fn (Builder $p) => $p->where('name', $permission))
                ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission)))
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->pluck('staff_id')
            ->all();
    }

    /**
     * One grouped notice per staff member and key while unread: insert at 1, or count the open
     * group up and move it to now. The text is written for the count this event reached; the
     * guard on `item_count` lets a later, concurrent event's text win.
     */
    private static function bump(string $staffId, StaffNoticeKind $kind, string $groupKey, ?string $itemId, ?string $ref): void
    {
        $row = DB::selectOne(
            "INSERT INTO staff_notice (staff_id, kind, severity, title, body, params, link, group_key, item_count, dedupe_key, created_at)
             VALUES (?, ?, 'info', ?, ?, ?::jsonb, ?, ?, 1, ?, ?)
             ON CONFLICT (staff_id, group_key) WHERE read_at IS NULL AND group_key IS NOT NULL
             DO UPDATE SET item_count = staff_notice.item_count + 1, created_at = EXCLUDED.created_at
             RETURNING notice_id, item_count",
            [
                $staffId,
                $kind->value,
                Str::limit($kind->title(1), 200, ''),
                Str::limit($kind->body(1, $ref), 1000, ''),
                self::params(1, $itemId, $ref),
                $kind->itemLink($itemId),
                $groupKey,
                'work:'.Str::uuid(),
                // The application clock, like every other Dahab time.
                now(),
            ],
        );

        $count = (int) $row->item_count;
        if ($count === 1) {
            return;
        }

        DB::update(
            'UPDATE staff_notice SET title = ?, body = ?, params = ?::jsonb, link = ? WHERE notice_id = ? AND item_count = ? AND read_at IS NULL',
            [
                Str::limit($kind->title($count), 200, ''),
                Str::limit($kind->body($count, null), 1000, ''),
                self::params($count, null, null),
                $kind->queueLink(),
                $row->notice_id,
                $count,
            ],
        );
    }

    private static function params(int $count, ?string $itemId, ?string $ref): string
    {
        return json_encode(array_filter(['count' => $count, 'ref' => $ref, 'item_id' => $itemId], fn ($v) => $v !== null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
