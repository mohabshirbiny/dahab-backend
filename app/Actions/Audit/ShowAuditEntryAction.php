<?php

namespace App\Actions\Audit;

use App\Models\Staff;
use App\Support\Audit\AuditEntryPresenter;
use App\Support\Audit\AuditQuery;

/**
 * One entry with everything recorded for it (spec 006 FR-004). An entry the
 * viewer may not see answers 404, like a missing one.
 */
final class ShowAuditEntryAction
{
    public function __construct(
        private readonly AuditQuery $query,
        private readonly AuditEntryPresenter $presenter,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Staff $viewer, int $auditId): array
    {
        $row = $this->query->visibleTo($viewer)->whereKey($auditId)->firstOrFail();

        return $this->presenter->present(collect([$row]), details: true)[0];
    }
}
