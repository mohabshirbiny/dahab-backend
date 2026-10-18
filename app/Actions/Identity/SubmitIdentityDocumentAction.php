<?php

namespace App\Actions\Identity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use App\Enums\UploadPurpose;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Services\IdentityDocumentStorage;
use App\Services\UploadTokenStore;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Post-login submission of an identity document (docs Part 2 §5).
 *
 * Every submission is a new row with the next `attempt_number` (testing fixes,
 * item 2); nothing is ever overwritten and no earlier image is deleted:
 *   1. New submission — no document yet → insert a `pending` row (audit
 *      `identity_document.submitted`).
 *   2. Resubmission   — the open document is `needs_resubmission` → it becomes
 *      `superseded` (keeping its images, reasons and reviewer) and a new
 *      `pending` row is inserted (audit `identity_document.resubmitted`).
 *
 * A `pending` row that is already awaiting review is refused with
 * `document_already_pending` — no accidental duplicates. While the latest
 * attempt is `rejected` the customer cannot send again
 * (`identity_rejected_contact_support`, docs/features/rejected-identity-reopen.md):
 * they contact customer service and staff move them back to waiting review
 * (ReopenIdentityDocumentAction). There is no limit
 * on attempts (owner's decision); the retention rules (`image_deleted_at`)
 * still apply to earlier attempts.
 */
final class SubmitIdentityDocumentAction
{
    public function __construct(
        private readonly UploadTokenStore $tokens,
        private readonly IdentityDocumentStorage $storage,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(
        Customer $actor,
        string $docKind,
        string $frontUploadToken,
        ?string $backUploadToken,
        RequestContext $ctx,
    ): IdentityDocument {
        $kind = IdentityDocumentKind::tryFrom($docKind) ?? throw DomainApiException::unsupportedDocKind();

        if ($kind === IdentityDocumentKind::EGYPTIAN_ID && $backUploadToken === null) {
            throw DomainApiException::unsupportedDocKind();
        }

        $frontRef = $this->tokens->resolve($frontUploadToken, $actor->customer_id, UploadPurpose::IDENTITY);
        $backRef = $backUploadToken !== null
            ? $this->tokens->resolve($backUploadToken, $actor->customer_id, UploadPurpose::IDENTITY)
            : null;

        if ($frontRef === null || ! $this->storage->exists($frontRef)) {
            throw DomainApiException::uploadTokenInvalid();
        }

        if ($backUploadToken !== null && ($backRef === null || ! $this->storage->exists($backRef))) {
            throw DomainApiException::uploadTokenInvalid();
        }

        $document = DB::transaction(function () use ($actor, $kind, $frontRef, $backRef, $ctx) {
            Customer::query()->whereKey($actor->customer_id)->lockForUpdate()->firstOrFail();

            $existing = IdentityDocument::query()
                ->where('customer_id', $actor->customer_id)
                ->inReview()
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === IdentityDocumentStatus::PENDING) {
                throw DomainApiException::documentAlreadyPending();
            }

            if ($existing === null) {
                $latest = IdentityDocument::query()
                    ->where('customer_id', $actor->customer_id)
                    ->orderByDesc('attempt_number')
                    ->first();

                if ($latest?->status === IdentityDocumentStatus::REJECTED) {
                    throw DomainApiException::identityRejectedContactSupport();
                }
            }

            $resubmission = $existing !== null && $existing->status === IdentityDocumentStatus::NEEDS_RESUBMISSION;

            if ($resubmission) {
                $existing->update(['status' => IdentityDocumentStatus::SUPERSEDED]);
            }

            $document = IdentityDocument::query()->create([
                'customer_id' => $actor->customer_id,
                'doc_kind' => $kind,
                'front_ref' => $frontRef,
                'back_ref' => $backRef,
                'status' => IdentityDocumentStatus::PENDING,
            ])->refresh(); // attempt_number is set by trg_identity_document_next_attempt

            $this->audit->execute(
                $resubmission ? AuditEvent::IDENTITY_DOCUMENT_RESUBMITTED : AuditEvent::IDENTITY_DOCUMENT_SUBMITTED,
                'success',
                ['doc_kind' => $kind->value, 'status' => IdentityDocumentStatus::PENDING->value, 'attempt_number' => $document->attempt_number]
                    + ($resubmission ? ['superseded_document_id' => $existing->document_id] : []),
                'identity_document',
                $document->document_id,
                $ctx,
                actorCustomerId: $actor->customer_id,
            );

            return $document;
        });

        $this->tokens->forget($frontUploadToken);
        if ($backUploadToken !== null) {
            $this->tokens->forget($backUploadToken);
        }

        return $document;
    }
}
