<?php

namespace App\Actions\Identity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\IdentityDocumentStatus;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard's "Move back to waiting" on a rejected customer
 * (docs/features/rejected-identity-reopen.md).
 *
 * A rejected customer cannot send their ID again; they contact customer
 * service. Staff then reopen the latest attempt:
 *
 *   document  rejected  → pending               (same images, same attempt number)
 *   customer  rejected  → pending_verification  (behind a suspension: the state it returns to)
 *
 * The reviewer, time, reasons and note of the rejection are cleared on the row
 * and kept in the audit row's `before`, so the normal Verify / Ask again /
 * Reject review applies to it again. A written reason is required
 * (`reason_required`). Only the customer's latest attempt can be
 * reopened, never a closed account. A second call finds the document pending
 * and is refused with `illegal_document_transition`. No message is sent to
 * the customer (the app shows "waiting for review" on its next reload).
 */
final class ReopenIdentityDocumentAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $documentId, ?string $reason, RequestContext $ctx): IdentityDocument
    {
        ReasonRule::assertPresent($reason);
        $reason = trim((string) $reason);

        return DB::transaction(function () use ($actor, $documentId, $reason, $ctx) {
            $document = IdentityDocument::query()->whereKey($documentId)->firstOrFail();

            // Lock order of the submit and review actions: customer first, then the document.
            $customer = Customer::query()->whereKey($document->customer_id)->lockForUpdate()->firstOrFail();
            $document = IdentityDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if ($document->status !== IdentityDocumentStatus::REJECTED) {
                throw DomainApiException::illegalDocumentTransition($document->status->value, IdentityDocumentStatus::PENDING->value);
            }

            $latest = (int) IdentityDocument::query()->where('customer_id', $customer->customer_id)->max('attempt_number');
            if ($document->attempt_number !== $latest) {
                throw DomainApiException::illegalDocumentTransition('rejected (not the latest attempt)', IdentityDocumentStatus::PENDING->value);
            }

            if ($customer->status === CustomerStatus::CLOSED) {
                throw DomainApiException::accountClosed();
            }

            $before = [
                'status' => $document->status->value,
                'customer_status' => $customer->status->value,
                'reviewed_by' => $document->reviewed_by,
                'reviewed_at' => $document->reviewed_at?->toIso8601String(),
                'review_reasons' => $document->review_reasons,
                'review_note' => $document->review_note,
            ];

            $document->update([
                'status' => IdentityDocumentStatus::PENDING,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_reasons' => null,
                'review_note' => null,
            ]);

            if ($customer->status === CustomerStatus::SUSPENDED) {
                if ($customer->status_before_suspension === CustomerStatus::REJECTED) {
                    $customer->setStateBehindSuspension(CustomerStatus::PENDING_VERIFICATION);
                    $customer->save();
                }
            } elseif ($customer->status === CustomerStatus::REJECTED) {
                $customer->transitionTo(CustomerStatus::PENDING_VERIFICATION);
                $customer->save();
            }

            $this->audit->execute(
                AuditEvent::IDENTITY_DOCUMENT_REOPENED,
                'success',
                [
                    'status' => IdentityDocumentStatus::PENDING->value,
                    'doc_kind' => $document->doc_kind->value,
                    'attempt_number' => $document->attempt_number,
                    'customer_id' => $customer->customer_id,
                    'customer_status' => $customer->status->value,
                ],
                'identity_document',
                $document->document_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: $before,
                reason: $reason,
            );

            return $document->setRelation('customer', $customer);
        });
    }
}
