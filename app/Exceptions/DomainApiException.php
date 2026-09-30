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

    /** Idempotency-Key missing or not a UUID on a route that requires one (spec 007 research R2). */
    public static function idempotencyKeyRequired(): self
    {
        return new self('idempotency_key_required', 400, 'An Idempotency-Key header (a UUID) is required.');
    }

    /** The key was already used for a different request — never replay another request's response. */
    public static function idempotencyKeyMismatch(): self
    {
        return new self('idempotency_key_mismatch', 422, 'This Idempotency-Key was already used for a different request.');
    }

    /** Reinstate first: a suspended customer is not suspended again (spec 007 FR-008). */
    public static function customerAlreadySuspended(): self
    {
        return new self('customer_already_suspended', 409, 'This customer is already suspended.');
    }

    public static function customerNotSuspended(): self
    {
        return new self('customer_not_suspended', 409, 'This customer is not suspended.');
    }

    public static function idempotencyInProgress(): self
    {
        return new self('idempotency_in_progress', 409, 'A request with this Idempotency-Key is still being processed.');
    }

    /** A ledger entry would take a customer account below zero (spec 008 FR-007; Part 2 §4, §8). */
    public static function insufficientFunds(): self
    {
        return new self('insufficient_funds', 409, 'There is not enough money in the wallet for this.');
    }

    /** A ledger entry is reversed at most once (spec 008 FR-010). */
    public static function ledgerAlreadyReversed(): self
    {
        return new self('ledger_already_reversed', 409, 'This ledger entry has already been reversed.');
    }

    /**
     * A top-up status move outside the state machine, or a lost race: the
     * notice was already credited, rejected or cancelled (spec 009 FR-025;
     * the guard trigger's SQLSTATE DH003 maps here too).
     */
    public static function illegalTopUpTransition(): self
    {
        return new self('illegal_topup_transition', 409, 'This transfer has already been closed or cannot move to that state.');
    }

    /**
     * A listing move outside `listing_transition`, or a lost race (spec 010
     * FR-015; the guard triggers' SQLSTATE DH004 maps here too).
     */
    public static function illegalListingTransition(): self
    {
        return new self('illegal_listing_transition', 409, 'This listing cannot move to that state.');
    }

    /** Only a draft or a listing sent back for changes can be edited (spec 010 FR-006). */
    public static function listingNotEditable(): self
    {
        return new self('listing_not_editable', 409, 'This listing can no longer be edited.');
    }

    /** A suspended seller's piece never reaches the market (spec 010 FR-030b). */
    public static function sellerSuspended(): self
    {
        return new self('seller_suspended', 409, 'The seller is suspended, so this listing cannot go live.');
    }

    /** The listing's karat was turned off while it waited for review (spec 010 FR-030c). */
    public static function karatDisabled(): self
    {
        return new self('karat_disabled', 409, "This listing's karat has been turned off, so it cannot go live.");
    }

    /** Mirrors the schema CHECK: anything but a pure diamond states its karat and weight (Part 2 §3). */
    public static function goldNeedsKaratWeight(): self
    {
        return new self('gold_needs_karat_weight', 422, 'A gold piece needs its karat and weight.');
    }

    /** At least one branch, each enabled (Part 2 §3). */
    public static function branchOptionsRequired(): self
    {
        return new self('branch_options_required', 422, 'Choose at least one open branch for this piece.');
    }

    /** Not accepted, or not the current version of the declaration (Part 2 §3, spec 010 FR-039). */
    public static function ownershipDeclarationRequired(): self
    {
        return new self('ownership_declaration_required', 422, 'Confirm the current ownership declaration to list the piece.');
    }

    /** Too few photos to send the listing for review (Part 2 §3, spec 010 FR-013). */
    public static function photoRequired(int $minimum): self
    {
        return new self('photo_required', 422, "Add at least {$minimum} photos before sending the piece for review.");
    }
}
