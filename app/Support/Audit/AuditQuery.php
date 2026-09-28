<?php

namespace App\Support\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Enums\StaffPermission;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Support\SystemActor;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audit entries one staff member may see, filtered (spec 006 FR-003,
 * FR-005, FR-010). Without audit.view_all the query is limited to the
 * viewer's own actions here — never on the client's word.
 */
final class AuditQuery
{
    /** @return Builder<AuditLog> */
    public function visibleTo(Staff $viewer): Builder
    {
        $query = AuditLog::query();

        if (! $viewer->can(StaffPermission::AUDIT_VIEW_ALL->value)) {
            $query->where('actor_staff_id', $viewer->staff_id);
        }

        return $query;
    }

    /** @return Builder<AuditLog> newest first */
    public function filtered(Staff $viewer, AuditFilters $filters): Builder
    {
        $query = $this->visibleTo($viewer)
            ->where('created_at', '>=', $filters->from)
            ->where('created_at', '<=', $filters->to);

        $this->category($query, $filters->category);

        if ($filters->actor === 'system') {
            $query->where('actor_staff_id', SystemActor::id());
        } elseif ($filters->actor !== null) {
            $query->where('actor_staff_id', $filters->actor);
        }
        if ($filters->action !== null) {
            $query->where('action', $filters->action);
        }
        if ($filters->entityType !== null) {
            $query->where('entity_type', $filters->entityType);
        }
        if ($filters->entityId !== null) {
            $query->where('entity_id', $filters->entityId);
        }

        return $query->orderByDesc('created_at')->orderByDesc('audit_id');
    }

    /** @param  Builder<AuditLog>  $query */
    public function after(Builder $query, ?AuditCursor $cursor): Builder
    {
        if ($cursor === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('created_at', '<', $cursor->createdAt)
            ->orWhere(fn (Builder $same) => $same->where('created_at', $cursor->createdAt)->where('audit_id', '<', $cursor->auditId)));
    }

    /** @param  Builder<AuditLog>  $query */
    private function category(Builder $query, ?AuditCategory $category): void
    {
        $known = array_map(fn (AuditEvent $e) => $e->value, AuditEvent::cases());

        match (true) {
            // "Everything" leaves out sign-ins and sessions (Clarification 1).
            $category === null => $query->whereNotIn('action', AuditEvent::codesIn(AuditCategory::SESSIONS)),
            // Codes no longer in the catalogue are shown under System, never dropped.
            $category === AuditCategory::SYSTEM => $query->where(fn (Builder $q) => $q
                ->whereIn('action', AuditEvent::codesIn(AuditCategory::SYSTEM))
                ->orWhereNotIn('action', $known)),
            default => $query->whereIn('action', AuditEvent::codesIn($category) ?: ['-']),
        };
    }
}
