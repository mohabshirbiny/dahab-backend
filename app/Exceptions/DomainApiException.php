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
    /**
     * @param  array<string, mixed>  $details  extra figures the client needs to act (spec 011:
     *                                         `insufficient_funds`, `price_moved`), rendered as `details`
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        string $message,
        public readonly array $details = [],
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
    /**
     * Spec 011 adds optional details for a buy request's deposit: `deposit_amount`,
     * `available` and `shortfall` (decimal strings).
     *
     * @param  array<string, string>  $details
     */
    public static function insufficientFunds(array $details = []): self
    {
        return new self('insufficient_funds', 409, 'There is not enough money in the wallet for this.', $details);
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

    /** The price moved beyond the tolerance since the buyer saw it (spec 011 FR-002). */
    public static function priceMoved(string $currentPrice, string $depositAmount): self
    {
        return new self('price_moved', 409, 'The price has changed since you saw it. Check the new price and send again.', [
            'current_price' => $currentPrice,
            'deposit_amount' => $depositAmount,
        ]);
    }

    /** A gold piece cannot be priced right now (no usable gold price; spec 011). */
    public static function priceUnavailable(): self
    {
        return new self('price_unavailable', 409, 'This piece cannot be priced right now. Try again in a few minutes.');
    }

    public static function alreadyInQueue(): self
    {
        return new self('already_in_queue', 409, 'You already have a request on this piece.');
    }

    public static function listingNotPurchasable(): self
    {
        return new self('listing_not_purchasable', 409, 'This piece cannot be requested now.');
    }

    public static function cannotBuyOwnListing(): self
    {
        return new self('cannot_buy_own_listing', 409, 'You cannot send a buy request on your own piece.');
    }

    public static function depositAgreementRequired(): self
    {
        return new self('deposit_agreement_required', 422, 'Accept the current deposit terms to send a request.');
    }

    public static function notInQueue(): self
    {
        return new self('not_in_queue', 409, 'This request is no longer in the queue.');
    }

    public static function notQueueHead(): self
    {
        return new self('not_queue_head', 409, 'Only the first request in the queue can be answered.');
    }

    public static function queueEmpty(): self
    {
        return new self('queue_empty', 409, 'There is no request waiting on this piece.');
    }

    public static function branchNotInOptions(): self
    {
        return new self('branch_not_in_options', 409, 'Choose one of the open branches you named for this piece.');
    }

    public static function buyerSuspended(): self
    {
        return new self('buyer_suspended', 409, 'This buyer cannot be accepted right now. You can decline the request.');
    }

    public static function branchHoursUnavailable(): self
    {
        return new self('branch_hours_unavailable', 409, 'The deadline at this branch cannot be worked out. Choose another branch.');
    }

    public static function orderNotCancellable(): self
    {
        return new self('order_not_cancellable', 409, 'Only an order waiting for delivery can be cancelled.');
    }

    /** SQLSTATE DH005 from the buy-request guards (spec 011). Orders use DH006 since spec 012. */
    public static function illegalBuyRequestTransition(): self
    {
        return new self('illegal_buy_request_transition', 409, 'This request cannot change that way.');
    }

    // Spec 012 — orders (research R21).

    /** SQLSTATE DH006 from the order guard, and every order Action's state check. */
    public static function illegalOrderTransition(): self
    {
        return new self('illegal_order_transition', 409, 'This order cannot do that now.');
    }

    public static function orderNotOpen(): self
    {
        return new self('order_not_open', 409, 'The branch can only change while the piece has not reached it.');
    }

    public static function deadlineNotRunning(): self
    {
        return new self('deadline_not_running', 409, 'That deadline is not running for this order now.');
    }

    public static function deadlineMustMoveForward(): self
    {
        return new self('deadline_must_move_forward', 422, 'The new deadline must be later than the current one and in the future.');
    }

    public static function inspectionCorrectionNotAllowed(): self
    {
        return new self('inspection_correction_not_allowed', 409, 'This result can no longer be corrected, or it is not the latest one.');
    }

    public static function priceNotSet(): self
    {
        return new self('price_not_set', 409, 'Dahab has not set the new price yet.');
    }

    public static function balanceDeadlinePassed(): self
    {
        return new self('balance_deadline_passed', 409, 'The time to pay the balance has passed.');
    }

    /** A safeguard: the seller's proceeds would not be positive (research R7). */
    public static function settlementNotPossible(): self
    {
        return new self('settlement_not_possible', 409, 'This order cannot be settled automatically. Dahab will contact you.');
    }

    /** 422, not Part 2's 401: a 401 makes the Dashboard sign the staff member out (research R10). */
    public static function invalidCollectionCode(int $attemptsLeft): self
    {
        return new self('invalid_collection_code', 422, 'That code is not right.', ['attempts_left' => $attemptsLeft]);
    }

    public static function handoverLocked(int $retryAfterSeconds): self
    {
        return new self('handover_locked', 429, 'Too many wrong codes. Try again later.', ['retry_after' => $retryAfterSeconds]);
    }

    /** A withdrawal move outside `withdrawal_transition`, or a lost race (spec 013; SQLSTATE DH007). */
    public static function illegalWithdrawalTransition(): self
    {
        return new self('illegal_withdrawal_transition', 409, 'This withdrawal cannot do that now.');
    }

    /** A payout-account move outside `payout_account_transition`, or a lost race (spec 013; SQLSTATE DH008). */
    public static function illegalPayoutAccountTransition(): self
    {
        return new self('illegal_payout_account_transition', 409, 'This payout account cannot do that now.');
    }

    /** No confirmed, unused, unexpired email confirmation of this customer for this amount and account (Part 1 §2.4, §9). */
    public static function emailConfirmationRequired(): self
    {
        return new self('email_confirmation_required', 403, 'Confirm this withdrawal from the link we emailed you first.');
    }

    /** The email link's token is unknown, expired, replaced by a newer link, or already used (spec 013 R5). */
    public static function confirmationInvalid(): self
    {
        return new self('confirmation_invalid', 422, 'This link has expired or was already used. Ask for a new one in the app.');
    }

    /** The payout-account declaration was not accepted, or is not the current version (spec 013 R14). */
    public static function declarationRequired(): self
    {
        return new self('declaration_required', 422, 'Confirm the account is yours and the name matches your ID.');
    }

    /** A pause opened by a change of the account in use covers now (Part 2 §8). */
    public static function withdrawalsPaused(string $pauseUntil): self
    {
        return new self('withdrawals_paused', 409, 'Withdrawals are paused after a change of payout account.', ['pause_until' => $pauseUntil]);
    }

    /** No verified account in use, or it is being removed, or it is not the customer's (Part 2 §8). */
    public static function payoutAccountNotActive(): self
    {
        return new self('payout_account_not_active', 409, 'This payout account cannot receive money now.');
    }

    /** A held withdrawal is never released (spec 013 Clarifications). */
    public static function withdrawalOnHold(): self
    {
        return new self('withdrawal_on_hold', 409, 'This withdrawal is on hold. Remove the hold before releasing it.');
    }

    /** The order is frozen by an open dispute (spec 014 FR-004). The ref is given only to the raiser and staff. */
    public static function orderFrozen(?string $disputeRef = null): self
    {
        return new self('order_frozen', 409, 'This order is on hold while Dahab looks into a problem.',
            $disputeRef === null ? [] : ['dispute_ref' => $disputeRef]);
    }

    /** Each party raises at most one dispute per order (spec 014 Clarification). */
    public static function disputeAlreadyRaised(): self
    {
        return new self('dispute_already_raised', 409, 'You have already reported a problem on this order.');
    }

    /** "Against the sale" is allowed only before payment (spec 014 FR-013). */
    public static function disputeOutcomeNotAllowed(): self
    {
        return new self('dispute_outcome_not_allowed', 409, 'A paid order cannot be cancelled from a dispute. Resume it, with compensation if needed.');
    }

    /** A dispute move outside `dispute_transition`, a change to a resolved dispute, or a lost race (SQLSTATE DH009). */
    public static function illegalDisputeTransition(): self
    {
        return new self('illegal_dispute_transition', 409, 'This dispute cannot do that now.');
    }

    /** Passed to oneself, to an inactive colleague, or to one without `dispute.handle` (spec 014 FR-009). */
    public static function assigneeNotEligible(): self
    {
        return new self('assignee_not_eligible', 422, 'Choose an active colleague who handles disputes.');
    }

    /** A request for more time is already waiting on this order (spec 014 FR-022). */
    public static function extensionRequestPending(): self
    {
        return new self('extension_request_pending', 409, 'Your request for more time is already waiting for an answer.');
    }

    /** A request move outside `extension_request_transition`, or a lost race (SQLSTATE DH010). */
    public static function illegalExtensionRequestTransition(): self
    {
        return new self('illegal_extension_request_transition', 409, 'This request has already been answered.');
    }

    /** Over the per-payment or the per-day compensation cap (Part 2 §9; spec 014 FR-015). */
    public static function compensationCapExceeded(string $perPayment, string $leftToday): self
    {
        return new self('compensation_cap_exceeded', 403, 'This is over your compensation limit.',
            ['per_payment' => $perPayment, 'left_today' => $leftToday]);
    }

    /** The proxy collects but none is named, or their ID was not checked (Part 2 §7; spec 014 FR-019). */
    public static function proxyDetailsMissing(): self
    {
        return new self('proxy_details_missing', 422, 'Check the ID of the person collecting against the named proxy first.');
    }
}
