<?php

namespace App\Actions\Identity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\IdentityDocumentStatus;
use App\Enums\IdentityReviewReason;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Notifications\CustomerVerificationRejectedNotification;
use App\Notifications\CustomerVerificationResubmissionNotification;
use App\Notifications\CustomerVerifiedNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard's "Verify / Ask again / Reject" decision on an identity
 * document (docs Part 2 §10).
 *
 *   verify              → document = verified,            customer = active
 *   request_resubmission→ document = needs_resubmission,  customer stays pending_verification
 *   reject              → document = rejected,            customer = rejected
 *
 * A verify or reject on a document that is not currently `pending` or
 * `needs_resubmission` is refused with `illegal_document_transition`. All
 * three branches share one transaction with the audit row; notifications
 * are queued after commit and never sent for a rolled-back review.
 */
final class ReviewIdentityDocumentAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  list<IdentityReviewReason>  $reasons  required for request_resubmission and reject
     */
    public function handle(
        Staff $actor,
        string $documentId,
        IdentityDocumentStatus $decision,
        array $reasons,
        ?string $note,
        RequestContext $ctx,
    ): IdentityDocument {
        if (! in_array($decision, [IdentityDocumentStatus::VERIFIED, IdentityDocumentStatus::REJECTED, IdentityDocumentStatus::NEEDS_RESUBMISSION], true)) {
            throw DomainApiException::illegalDocumentTransition('pending', $decision->value);
        }

        [$document, $customer] = DB::transaction(function () use ($actor, $documentId, $decision, $reasons, $note, $ctx) {
            $document = IdentityDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if (! in_array($document->status, [IdentityDocumentStatus::PENDING, IdentityDocumentStatus::NEEDS_RESUBMISSION], true)) {
                throw DomainApiException::illegalDocumentTransition($document->status->value, $decision->value);
            }

            $customer = Customer::query()->whereKey($document->customer_id)->lockForUpdate()->firstOrFail();

            $previousStatus = $document->status;

            $document->update([
                'status' => $decision,
                'reviewed_by' => $actor->staff_id,
                'reviewed_at' => now(),
                'review_reasons' => $reasons === [] ? null : array_map(fn (IdentityReviewReason $r) => $r->value, $reasons),
                'review_note' => $note,
            ]);

            match ($decision) {
                IdentityDocumentStatus::VERIFIED => $this->activate($customer),
                IdentityDocumentStatus::REJECTED => $this->reject($customer),
                default => null, // needs_resubmission: customer stays pending_verification.
            };

            $this->audit->execute(
                $this->auditEventFor($decision),
                'success',
                [
                    'decision' => $decision->value,
                    'doc_kind' => $document->doc_kind->value,
                    'customer_id' => $customer->customer_id,
                    'customer_status' => $customer->status->value,
                    'reasons' => array_map(fn (IdentityReviewReason $r) => $r->value, $reasons),
                ],
                'identity_document',
                $document->document_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['status' => $previousStatus->value],
                reason: $note,
            );

            return [$document->setRelation('customer', $customer), $customer];
        });

        // After commit: dispatch the matching notification exactly once.
        $notifiable = $customer;
        match ($decision) {
            IdentityDocumentStatus::VERIFIED => $notifiable->notify(new CustomerVerifiedNotification),
            IdentityDocumentStatus::REJECTED => $notifiable->notify(new CustomerVerificationRejectedNotification($note, $this->reasonValues($reasons))),
            IdentityDocumentStatus::NEEDS_RESUBMISSION => $notifiable->notify(new CustomerVerificationResubmissionNotification($this->reasonValues($reasons), $note)),
            default => null,
        };

        return $document;
    }

    private function activate(Customer $customer): void
    {
        if ($customer->status !== CustomerStatus::ACTIVE) {
            $customer->transitionTo(CustomerStatus::ACTIVE);
            $customer->save();
        }
    }

    private function reject(Customer $customer): void
    {
        if ($customer->status !== CustomerStatus::REJECTED) {
            $customer->transitionTo(CustomerStatus::REJECTED);
            $customer->save();
        }
    }

    /** @param list<IdentityReviewReason> $reasons @return list<string> */
    private function reasonValues(array $reasons): array
    {
        return array_map(fn (IdentityReviewReason $r) => $r->value, $reasons);
    }

    private function auditEventFor(IdentityDocumentStatus $decision): AuditEvent
    {
        return match ($decision) {
            IdentityDocumentStatus::VERIFIED => AuditEvent::CUSTOMER_VERIFICATION_APPROVED,
            IdentityDocumentStatus::REJECTED => AuditEvent::CUSTOMER_VERIFICATION_REJECTED,
            IdentityDocumentStatus::NEEDS_RESUBMISSION => AuditEvent::IDENTITY_DOCUMENT_RESUBMISSION_REQUESTED,
            default => AuditEvent::IDENTITY_DOCUMENT_REJECTED,
        };
    }
}
