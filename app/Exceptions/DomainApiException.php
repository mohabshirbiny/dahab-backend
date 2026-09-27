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

    public static function closureExists(): self
    {
        return new self('closure_exists', 409, 'This date is already closed for that branch or for all branches.');
    }

    public static function closureInPast(): self
    {
        return new self('closure_in_past', 409, 'Past closures cannot be removed: deadlines were already counted with them.');
    }

    /** An unverified (pending or rejected) customer calling a gated action (spec 002 FR-031). */
    public static function verificationRequired(): self
    {
        return new self('verification_required', 403, 'Verify your identity before doing this.');
    }

    /** No gold price has ever been recorded (spec 005). */
    public static function noGoldPrice(): self
    {
        return new self('no_gold_price', 409, 'No gold price is set yet.');
    }

    /** A karat's buyers-pay price would fall below its sellers-get price, or a price would be zero or less. */
    public static function priceInverted(int $karatCode): self
    {
        return new self('price_inverted', 422, "The {$karatCode}K prices would be inverted: buyers would pay less than sellers get, or a price would be zero or less.");
    }

    public static function priceFeedHealthy(): self
    {
        return new self('price_feed_healthy', 409, 'A manual price is only accepted while the price feed is down.');
    }

    public static function manualPriceNotPending(): self
    {
        return new self('manual_price_not_pending', 409, 'This manual price is no longer waiting for confirmation.');
    }

    public static function confirmerMustDiffer(): self
    {
        return new self('confirmer_must_differ', 403, 'Someone other than the person who entered this price must confirm it.');
    }
}
