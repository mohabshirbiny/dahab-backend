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

    /** The staff set-password link is unknown, used, voided, expired, or its member is disabled (spec 020 R3). */
    public static function resetLinkInvalid(): self
    {
        return new self('reset_link_invalid', 422, 'This link is no longer valid. Ask for a new one from the sign-in page.');
    }

    /** A founder is never disabled through the API (spec 020, Part 2 §10). */
    public static function founderProtected(): self
    {
        return new self('founder_protected', 403, 'A founder account cannot be disabled.');
    }

    public static function staffAlreadyDisabled(): self
    {
        return new self('staff_already_disabled', 409, 'This staff member is already disabled.');
    }

    public static function staffNotDisabled(): self
    {
        return new self('staff_not_disabled', 409, 'This staff member is not disabled.');
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

    /** A day can be closed only once it has ended in Cairo (spec 015 FR-014). */
    public static function dayNotEnded(): self
    {
        return new self('day_not_ended', 422, 'This day has not ended yet. It can be closed from midnight.');
    }

    /** The day is closed and locked; it never changes (spec 015 FR-014, daily_close_no_reopen). */
    public static function dayAlreadyClosed(): self
    {
        return new self('day_already_closed', 409, 'This day is already closed.');
    }

    /** A credit note on a buyer invoice: Dahab charged the buyer nothing to correct (spec 016 Clarifications). */
    public static function invoiceNotCreditable(): self
    {
        return new self('invoice_not_creditable', 409, 'Only a seller invoice can be credited.');
    }

    /** The credit notes on an invoice never add up to more than it (spec 016 FR-019). */
    public static function creditExceedsInvoice(?string $remaining = null): self
    {
        return new self('credit_exceeds_invoice', 422, 'The credit note is larger than what is left of the invoice.',
            $remaining === null ? [] : ['remaining' => $remaining]);
    }

    // --- Spec 017: the customer account (research R11) ---

    /** The new phone or email belongs to another customer. */
    public static function contactTaken(): self
    {
        return new self('contact_taken', 409, 'This number or address is already used by another account.');
    }

    public static function sameContact(): self
    {
        return new self('same_contact', 422, 'This is already your number or address.');
    }

    public static function changeCodeInvalid(int $triesLeft): self
    {
        return new self('change_code_invalid', 422, 'This code is wrong or has expired.', ['tries_left' => $triesLeft]);
    }

    public static function changeCodeLocked(): self
    {
        return new self('change_code_locked', 429, 'Too many wrong codes. Ask for a new one.');
    }

    public static function changeLinkInvalid(): self
    {
        return new self('change_link_invalid', 410, 'This link has expired or was already used.');
    }

    public static function currentPasswordWrong(): self
    {
        return new self('current_password_wrong', 422, 'Your current password is not right.');
    }

    public static function currentSession(): self
    {
        return new self('current_session', 422, 'This is the device you are using. Sign out instead.');
    }

    /** Raised by guard DH013 when a closed customer would get a new row. */
    public static function accountClosed(): self
    {
        return new self('account_closed', 409, 'This account is closed.');
    }

    /** @param  list<array{code: string, count: int}>  $blockers */
    public static function accountHasOpenItems(array $blockers): self
    {
        return new self('account_has_open_items', 409, 'Finish or cancel what is in progress before closing the account.', ['blockers' => $blockers]);
    }

    public static function listingNotSaveable(): self
    {
        return new self('listing_not_saveable', 422, 'Only a piece on the market can be saved.');
    }

    public static function savedLimitReached(int $limit): self
    {
        return new self('saved_limit_reached', 422, 'You have saved as many pieces as you can.', ['limit' => $limit]);
    }

    public static function listingNotReportable(): self
    {
        return new self('listing_not_reportable', 422, 'This piece cannot be reported.');
    }

    public static function reportAlreadyOpen(): self
    {
        return new self('report_already_open', 409, 'You already reported this piece. We are looking at it.');
    }

    public static function reportNotOpen(): self
    {
        return new self('report_not_open', 409, 'This report was already handled.');
    }

    /** Guard DH014: an inbox item only gets read. A bug if reached through the API. */
    public static function notificationImmutable(): self
    {
        return new self('notification_immutable', 409, 'This notification cannot change.');
    }

    /** Spec 018: the free-relist window has passed (or there never was one for this order). */
    public static function freeRelistExpired(): self
    {
        return new self('free_relist_expired', 409, 'The time to relist this piece for free has passed.');
    }

    /** Spec 018: the one free relist of this order was already made. */
    public static function alreadyRelisted(): self
    {
        return new self('already_relisted', 409, 'This piece was already relisted.');
    }

    /** Spec 018: not settled, the buyer before collecting, or a cancelled order. */
    public static function ratingNotAvailable(): self
    {
        return new self('rating_not_available', 409, 'This order cannot be rated now.');
    }

    /** Spec 018: more than thirty days since the rating opened. */
    public static function ratingClosed(): self
    {
        return new self('rating_closed', 409, 'The time to rate this order has passed.');
    }

    /** Spec 018: one rating per party and order. */
    public static function alreadyRated(): self
    {
        return new self('already_rated', 409, 'You already rated this order.');
    }

    /** Guard DH016: a rating or the free-relist window never changes. A bug if reached through the API. */
    public static function ratingImmutable(): self
    {
        return new self('rating_immutable', 409, 'This cannot change.');
    }

    /** The PDF is built after the payment or credit note commits (spec 016 FR-023a). */
    public static function documentNotReady(): self
    {
        return new self('document_not_ready', 409, 'This document is being prepared. Try again in a few minutes.');
    }

    // ---- Spec 019: app text ----

    /** Someone saved the draft since this copy was read (A2: one shared draft per key, optimistic check). */
    public static function contentDraftChanged(int $current): self
    {
        return new self('content_draft_changed', 409, 'This text was changed by someone else. Reload it and try again.', ['draft_version' => $current]);
    }

    /** D7: a template that carries a one-time code or a link is shown, not edited. */
    public static function contentKeyReadOnly(): self
    {
        return new self('content_key_read_only', 409, 'This text cannot be edited here.');
    }

    /** The key was removed from the app (retired): it takes no new version. */
    public static function contentKeyRetired(): self
    {
        return new self('content_key_retired', 409, 'This text is no longer used by the app.');
    }

    public static function contentNoDraft(): self
    {
        return new self('content_no_draft', 404, 'There is no draft for this text.');
    }

    /** @param  list<string>  $names  the `{names}` that are not allowed for this key */
    public static function contentInvalidPlaceholder(array $names): self
    {
        return new self('content_invalid_placeholder', 422, 'This text uses a placeholder that is not allowed: '.implode(', ', $names).'.', ['placeholders' => $names]);
    }

    /** @param  list<string>  $names  the required `{names}` the text dropped */
    public static function contentMissingRequiredPlaceholder(array $names): self
    {
        return new self('content_missing_required_placeholder', 422, 'This text must keep: '.implode(', ', array_map(fn ($n) => '{'.$n.'}', $names)).'.', ['placeholders' => $names]);
    }

    /** @param  list<string>  $languages  `en` and/or `ar` */
    public static function contentLanguageMissing(array $languages): self
    {
        return new self('content_language_missing', 422, 'Both English and Arabic are required.', ['languages' => $languages]);
    }

    /** @param  list<string>  $languages  the languages above the limit */
    public static function contentTooLong(int $max, array $languages): self
    {
        return new self('content_too_long', 422, "This text is longer than the {$max} characters allowed.", ['max_length' => $max, 'languages' => $languages]);
    }

    /** A Help entry with this slug already exists. */
    public static function contentFaqExists(string $slug): self
    {
        return new self('content_faq_exists', 409, 'A Help entry with this name already exists.', ['slug' => $slug]);
    }

    /** The slug names no Help entry. */
    public static function contentFaqUnknown(): self
    {
        return new self('content_faq_unknown', 404, 'There is no such Help entry.');
    }

    /** Publishing or saving the text it already has. */
    public static function contentUnchanged(): self
    {
        return new self('content_unchanged', 422, 'Nothing changed.');
    }

    public static function contentNothingToPublish(): self
    {
        return new self('content_nothing_to_publish', 422, 'There are no drafts to publish.');
    }

    /** @param  array<string, array{code: string, message: string}>  $errors  one per key that cannot be published */
    public static function contentInvalid(array $errors): self
    {
        return new self('content_invalid', 422, 'Some drafts cannot be published.', ['keys' => $errors]);
    }

    /** Guard DH017: published text history never changes. A bug if reached through the API. */
    public static function contentHistoryImmutable(): self
    {
        return new self('content_history_immutable', 409, 'Published text history cannot be changed.');
    }

    // ---- Spec 019: legal publishing and acceptance ----

    /**
     * Every state-changing customer request is refused until the live terms or privacy are accepted.
     *
     * @param  list<array{code: string, version: int, legal_doc_id: int, reason: string}>  $required
     */
    public static function legalAcceptanceRequired(array $required): self
    {
        return new self('legal_acceptance_required', 409, 'Please read and accept the updated terms to continue.', ['required' => $required]);
    }

    /** The id sent is not the live version of a document that is accepted this way. */
    public static function legalDocumentNotCurrent(): self
    {
        return new self('legal_document_not_current', 422, 'This version of the document is no longer the current one.');
    }

    /** Nothing waits to be accepted for this document. */
    public static function legalAcceptanceNotRequired(): self
    {
        return new self('legal_acceptance_not_required', 409, 'There is nothing to accept for this document.');
    }

    public static function legalBodyRequired(): self
    {
        return new self('legal_body_required', 422, 'Both the English and the Arabic text are required.');
    }

    public static function legalCodeUnknown(): self
    {
        return new self('legal_code_unknown', 404, 'There is no such legal document.');
    }

    /** Two publishes of one document raced, or the version number was taken. */
    public static function legalVersionConflict(): self
    {
        return new self('legal_version_conflict', 409, 'Another version of this document was just published. Reload and try again.');
    }

    /** Guard DH018: a published legal version never changes. A bug if reached through the API. */
    public static function legalHistoryImmutable(): self
    {
        return new self('legal_document_immutable', 409, 'A published legal version cannot be changed.');
    }

    // ---- Spec 019 US7: category controls and what customers can see ----

    /**
     * New listings in a category are stopped (also the answer while everything is stopped).
     *
     * @param  array{en: string, ar: string}  $message  what staff wrote for customers
     */
    public static function categoryStopped(array $message): self
    {
        return new self('category_stopped', 409, $message['en'] !== '' ? $message['en'] : 'New listings in this category are paused for a short while.', ['message' => $message]);
    }

    /**
     * A category is paused: no request for more time on its orders.
     *
     * @param  array{en: string, ar: string}  $message
     */
    public static function categoryPaused(array $message): self
    {
        return new self('category_paused', 409, $message['en'] !== '' ? $message['en'] : 'This category is paused for a short while.', ['message' => $message]);
    }

    /**
     * Everything is stopped: no new listing, buy request or withdrawal, and no release.
     *
     * @param  array{en: string, ar: string}  $message
     */
    public static function platformStopped(array $message): self
    {
        return new self('platform_stopped', 409, $message['en'] !== '' ? $message['en'] : 'Dahab is paused for a short while. Your money and pieces are safe.', ['message' => $message]);
    }

    public static function controlCategoryRequired(): self
    {
        return new self('control_category_required', 422, 'Choose the category this control is for.');
    }

    public static function controlAlreadyActive(): self
    {
        return new self('control_already_active', 409, 'This control is already on.');
    }

    public static function controlNotActive(): self
    {
        return new self('control_not_active', 409, 'This control is not on.');
    }

    /** Guard DH019: a control is cleared once and never edited. A bug if reached through the API. */
    public static function controlHistoryImmutable(): self
    {
        return new self('control_history_immutable', 409, 'A category control cannot be edited or deleted.');
    }

    /** The generic settings PATCH does not change the `visibility` group. */
    public static function settingNotEditableHere(): self
    {
        return new self('setting_not_editable_here', 422, 'This setting is changed from Switches.');
    }

    // ---- Spec 019 US6: market makers ----

    public static function promoKindNotAvailable(): self
    {
        return new self('promo_kind_not_available', 422, 'Only market-maker codes can be made here.');
    }

    public static function promoCustomerNotMarketMaker(): self
    {
        return new self('promo_customer_not_market_maker', 422, 'The account a code is tied to must be a market maker.');
    }

    public static function promoCodeExists(): self
    {
        return new self('promo_code_exists', 409, 'There is already a code with this name, or this account already has one.');
    }

    public static function alreadyApproved(): self
    {
        return new self('already_approved', 409, 'This piece is already approved for market makers.');
    }

    public static function listingTooNew(int $days): self
    {
        return new self('listing_too_new', 422, "A piece must have been listed for {$days} days before it is approved.", ['min_days' => $days]);
    }

    public static function listingNotLive(): self
    {
        return new self('listing_not_live', 409, 'Only a live piece can be approved.');
    }

    /** Guard DH020: market-maker records are append-only. A bug if reached through the API. */
    public static function promoHistoryImmutable(): self
    {
        return new self('promo_history_immutable', 409, 'Market-maker records cannot be changed or deleted.');
    }

    /** A market-maker code cannot be used for this request (spec 019 FR-063): the reason says why. */
    public static function promoCodeInvalid(string $reason): self
    {
        return new self('promo_code_invalid', 422, 'This code cannot be used for this piece.', ['reason' => $reason]);
    }

    /** The account holds no active market-maker code. */
    public static function marketMakerOnly(): self
    {
        return new self('market_maker_only', 403, 'This is for market-maker accounts.');
    }

    // ---- Spec 021: founder-account security ----

    public static function deviceApprovalNotPending(): self
    {
        return new self('device_approval_not_pending', 409, 'This request is no longer waiting for a decision.');
    }

    public static function founderOnly(): self
    {
        return new self('founder_only', 403, 'Only a founder can do this.');
    }

    /** Approving or refusing your own device, or freezing yourself. */
    public static function founderSelfAction(): self
    {
        return new self('founder_self_action', 403, 'You cannot do this for your own account.');
    }

    public static function freezeTargetInvalid(): self
    {
        return new self('freeze_target_invalid', 422, 'Only the other founder, while active, can be frozen.');
    }

    public static function alreadyFrozen(): self
    {
        return new self('already_frozen', 409, 'This account is already frozen.');
    }

    public static function notFrozen(): self
    {
        return new self('not_frozen', 409, 'This account is not frozen.');
    }

    /** A founder confirms before the first confirmation exists. */
    public static function unfreezeOrder(): self
    {
        return new self('unfreeze_order', 409, 'The first confirmation comes first: the frozen founder or the named stand-in.');
    }

    public static function unfreezeAlreadyConfirmed(): self
    {
        return new self('unfreeze_already_confirmed', 409, 'This confirmation has already been given.');
    }

    public static function deviceNotKnown(): self
    {
        return new self('device_not_known', 403, 'Use a device this account has signed in from before.');
    }
}
