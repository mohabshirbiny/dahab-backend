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
 * Two paths, one Action:
 *   1. New submission — no open document exists → insert a `pending` row.
 *   2. Resubmission   — a `needs_resubmission` row exists → replace its
 *      front/back refs on the same row and reset status to `pending`, so
 *      the reviewer sees an audited resubmission on the original document
 *      rather than a duplicate account.
 *
 * A `pending` row that is already awaiting review is refused with
 * `document_already_pending` — no accidental duplicates.
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

            if ($existing !== null && $existing->status === IdentityDocumentStatus::NEEDS_RESUBMISSION) {
                // Delete the old (superseded) objects so nothing orphans on disk.
                $this->forgetIfPresent($existing->front_ref);
                $this->forgetIfPresent($existing->back_ref);

                $existing->update([
                    'doc_kind' => $kind,
                    'front_ref' => $frontRef,
                    'back_ref' => $backRef,
                    'status' => IdentityDocumentStatus::PENDING,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_reasons' => null,
                    'review_note' => null,
                ]);

                $this->audit->execute(
                    AuditEvent::IDENTITY_DOCUMENT_RESUBMITTED,
                    'success',
                    ['doc_kind' => $kind->value, 'status' => IdentityDocumentStatus::PENDING->value],
                    'identity_document',
                    $existing->document_id,
                    $ctx,
                    actorCustomerId: $actor->customer_id,
                );

                return $existing;
            }

            $document = IdentityDocument::query()->create([
                'customer_id' => $actor->customer_id,
                'doc_kind' => $kind,
                'front_ref' => $frontRef,
                'back_ref' => $backRef,
                'status' => IdentityDocumentStatus::PENDING,
            ]);

            $this->audit->execute(
                AuditEvent::IDENTITY_DOCUMENT_SUBMITTED,
                'success',
                ['doc_kind' => $kind->value, 'status' => IdentityDocumentStatus::PENDING->value],
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

    private function forgetIfPresent(?string $ref): void
    {
        if ($ref !== null && $this->storage->exists($ref)) {
            $this->storage->delete($ref);
        }
    }
}
