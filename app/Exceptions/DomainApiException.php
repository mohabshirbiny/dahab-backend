<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused business operation with a stable machine-readable `code`
 * (docs Part 2 §12), rendered centrally in bootstrap/app.php as
 * `{ message, code }`. Auth failures use AuthApiException instead.
 */
class DomainApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function documentAlreadyPending(): self
    {
        return new self('document_already_pending', 409, 'An identity document is already waiting for review.');
    }

    public static function unsupportedDocKind(): self
    {
        return new self('unsupported_doc_kind', 422, 'Unsupported identity document kind.');
    }

    /** Unknown, expired, already used, someone else's, or for another purpose — deliberately indistinguishable. */
    public static function uploadTokenInvalid(): self
    {
        return new self('upload_token_invalid', 422, 'The upload token is invalid or has expired.');
    }

    public static function illegalDocumentTransition(string $from, string $to): self
    {
        return new self('illegal_document_transition', 409, "An identity document cannot move from {$from} to {$to}.");
    }

    public static function documentImageDeleted(): self
    {
        return new self('document_image_deleted', 410, 'The image of this document has been deleted.');
    }

    /** Granting/removing a permission you do not hold, or editing your own role or assignment (spec 002 FR-025/FR-026). */
    public static function escalationDenied(): self
    {
        return new self('escalation_denied', 403, 'You cannot grant or remove access you do not hold, or change your own access.');
    }

    /** The change would leave no active staff member able to manage roles (spec 002 FR-023). */
    public static function lastRoleManager(): self
    {
        return new self('last_role_manager', 409, 'At least one active staff member must keep the permission to manage roles.');
    }

    public static function roleInUse(int $count): self
    {
        return new self('role_in_use', 409, "This role is held by {$count} staff member(s). Reassign them before deleting it.");
    }

    public static function reasonRequired(): self
    {
        return new self('reason_required', 422, 'A reason is required for this change.');
    }

    public static function wrongBranch(): self
    {
        return new self('wrong_branch', 403, 'This record belongs to another branch.');
    }

    /** An unverified (pending or rejected) customer calling a gated action (spec 002 FR-031). */
    public static function verificationRequired(): self
    {
        return new self('verification_required', 403, 'Verify your identity before doing this.');
    }
}
