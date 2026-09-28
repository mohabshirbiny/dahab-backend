<?php

namespace App\Actions\Customers;

use App\Enums\AuditEvent;
use App\Enums\StaffPermission;
use App\Exceptions\AuthApiException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Support\Audit\AuditCursor;
use App\Support\Audit\AuditEntryPresenter;
use App\Support\Audit\AuditQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * A customer file's History (spec 007 US3 / FR-011): the audit entries where
 * the customer acted, or where they or one of their identity documents is the
 * subject — newest first, keyset-paginated, labelled as in the Audit log.
 * Raw token refreshes are left out. Visibility follows the viewer's audit
 * permission (AuditQuery::visibleTo): view_own holders see only their own actions.
 */
final class ListCustomerActivityAction
{
    public function __construct(
        private readonly AuditQuery $query,
        private readonly AuditEntryPresenter $presenter,
    ) {}

    /** @return array{entries: list<array<string, mixed>>, next_cursor: ?string} */
    public function handle(Staff $viewer, string $customerId, ?AuditCursor $cursor, int $perPage): array
    {
        if (! $viewer->can(StaffPermission::AUDIT_VIEW_ALL->value) && ! $viewer->can(StaffPermission::AUDIT_VIEW_OWN->value)) {
            throw AuthApiException::permissionDenied();
        }

        $customer = Customer::query()->findOrFail($customerId);
        $documents = IdentityDocument::query()->where('customer_id', $customer->customer_id)->pluck('document_id')->all();

        $base = $this->query->visibleTo($viewer)
            ->where(fn (Builder $q) => $q
                ->where('actor_customer_id', $customer->customer_id)
                ->orWhere(fn (Builder $s) => $s->where('entity_type', 'customer')->where('entity_id', $customer->customer_id))
                ->when($documents !== [], fn (Builder $w) => $w->orWhere(fn (Builder $d) => $d
                    ->where('entity_type', 'identity_document')->whereIn('entity_id', $documents))))
            ->where('action', '!=', AuditEvent::TOKEN_ROTATED->value)
            ->orderByDesc('created_at')->orderByDesc('audit_id');

        $rows = $this->query->after($base, $cursor)->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        /** @var AuditLog|null $last */
        $last = $rows->last();

        return [
            'entries' => $this->presenter->present($rows),
            'next_cursor' => $hasMore && $last !== null
                ? (new AuditCursor((string) $last->getRawOriginal('created_at'), $last->audit_id))->encode()
                : null,
        ];
    }
}
