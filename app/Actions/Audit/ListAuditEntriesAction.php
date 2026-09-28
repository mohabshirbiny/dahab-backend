<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Support\Audit\AuditCursor;
use App\Support\Audit\AuditEntryPresenter;
use App\Support\Audit\AuditFilters;
use App\Support\Audit\AuditQuery;

/**
 * One page of the audit log for a staff member (spec 006 FR-001–FR-003,
 * FR-005): newest first, keyset-paginated, with the total under the same
 * filters and visibility.
 */
final class ListAuditEntriesAction
{
    public function __construct(
        private readonly AuditQuery $query,
        private readonly AuditEntryPresenter $presenter,
    ) {}

    /** @return array{entries: list<array<string, mixed>>, total: int, next_cursor: ?string} */
    public function handle(Staff $viewer, AuditFilters $filters, ?AuditCursor $cursor, int $perPage): array
    {
        $base = $this->query->filtered($viewer, $filters);
        $total = (clone $base)->reorder()->count();

        $rows = $this->query->after($base, $cursor)->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        /** @var AuditLog|null $last */
        $last = $rows->last();

        return [
            'entries' => $this->presenter->present($rows),
            'total' => $total,
            'next_cursor' => $hasMore && $last !== null
                ? (new AuditCursor((string) $last->getRawOriginal('created_at'), $last->audit_id))->encode()
                : null,
        ];
    }
}
