# Spec 022 — Change the price of a live piece

**Status**: Decisions D22-1…3 answered by the owner (2026-10-11); clarifications C1–C3 answered (2026-10-11); planned (`plan.md`, `tasks.md`), not built · **Repos**: Backend, Dashboard (read only), Customer app · **Priority**: independent of 020, 021, 023–025

## Why

The piece page has a red-flagged *Change fee* button and a prototype screen (`R.editprice`) with a fixed sample piece. The prototype promises: "You can change your making charge whenever no one has an open request on the piece. The listing keeps its history, so you don't lose the views it has built up." The Backend cannot do it: a listing is editable only as a draft or when it was sent back for changes (`ListingState::isEditable`).

## What exists today

- `PATCH /customer/me/listings/{id}` edits draft and changes-requested listings; reviewed listings re-enter review through the existing decisions.
- A buyer's price is locked at the request (spec 011); a live piece's displayed price comes from the pricing calculator, so only the seller's own figures (the making charge for gold, the asking price for stones and gold with diamond) can change.
- A live piece with no buyer in line is state `live`; once a buyer joins it is `reserved`.
- Settlement (spec 012, `OrderSettlement::compute`) reads `listing.making_charge_per_g` / `listing.asking_price` at payment time; only the gold rate is locked on the buy request. The figures therefore must never move once a buyer is in line — today nothing in the database stops it (the listing guard checks state moves only).

## Scope

### Story 1 — The seller changes the figure on a live piece

1. **Given** a seller's own piece in state `live`, **When** they change the making charge (gold) or the asking price (stone, gold with diamond), **Then** the new figure takes effect at once on the market, Browse and saved pieces, and the previous figure is kept in the listing's price history with who and when.
2. **Given** a buyer already in line (state `reserved` or later: `reserved`, `accepted`, `at_inspection`, `settling`, `sold`), **Then** the change is refused (`listing_has_requests`, 409) and the piece page explains why; prices already locked are never touched. Any other state that is not `live` (draft, in review, changes requested, on hold, withdrawn, rejected, returning to the seller…) is refused with `listing_not_live` (409).
3. The change is refused for a suspended seller (the trade gate, `account_suspended`; the seller's live pieces are also on hold), for a piece in review (`listing_not_live`), and for a figure that fails the same validation as creation (range, decimals) or equals the current figure (`validation_failed`, 422).
4. **D22-1:** the change goes live straight away; there is no new review and no state move.
5. The seller sees the new "You would receive" figure before confirming (the live quote `GET /reference/quote` already exists), and the buyer-facing price is recalculated from the calculator, not typed. A buyer who saw the old price and sends a request is protected by the existing `price_moved` check (spec 011 tolerance).
6. **C2:** refused with `platform_stopped` while Switches "stop everything" is on; allowed under "stop new listings" and "pause category".
7. **C3:** refused with `listing_price_locked` (409) while the piece is approved for market makers (spec 019 6B).
8. Every change is audited (`listing.price_changed`, actor = the seller, old and new figure).

### Story 2 — Staff can see it

The staff listing detail shows the history of price changes with times and the actor; no new staff action, no new permission (any listing permission reads it, as today).

## Surfaces

| Repo | Change |
|---|---|
| Backend | `POST /customer/me/listings/{listing}/price` (C1) for state `live` with one field; the `listing_price_change` table with an append-only guard and a listing guard that freezes the price columns outside draft / changes requested / live (DH023); codes `listing_has_requests`, `listing_price_locked`; `can_change_price` on the seller's listing; `price_history` on the staff listing detail; audit `listing.price_changed`; no seller or saver notice (D22-2); Postman, OpenAPI |
| Dashboard | the price-history block on the listing detail |
| Customer app | replace the prototype `EditPriceScreen` with a real form on the seller's own piece (current figure, field, the live "You would receive"), remove the red flag and the sample piece, errors for `listing_has_requests`, `listing_price_locked`, `listing_not_live`, `platform_stopped` |

## Depends on / used by

Uses the pricing calculator and the listing history; independent of every other spec here. Spec 023's first-sale advance must not break the locked-price rule this spec relies on: once 023 pays an advance on a piece, its price must not change (023 adds its own refusal beside C3's).

## Decisions (owner, 2026-10-11)

| ID | Question | Decision |
|---|---|---|
| D22-1 | Does a price change on a live piece need review again? | No, while it only moves the seller's own figure within the same limits |
| D22-2 | Are the people who *saved* the piece told of a change? | No (Saved pieces "don't lock a price", spec 017). The seller gets no notice either: they made the change |
| D22-3 | Is there a limit on how often or how far a price moves? | None; the same validation as creation, the existing per-customer listing throttle, and the audit log shows abuse |

## Clarifications

### Session 2026-10-11

- Q: Which endpoint shape should the price change use? → A: **C1** a new `POST /customer/me/listings/{listing}/price` (trade gate, `Idempotency-Key`), body only `making_charge_per_g` (gold) or `asking_price` (diamond, gold with diamond). `PATCH` and its `listing_not_editable` contract stay unchanged (non-breaking).
- Q: When do Switches (spec 019) refuse a price change? → A: **C2** only under "stop everything" (`platform_stopped`), like buy requests and withdrawals.
- Q: What happens when the seller changes the price of a piece approved for market makers? → A: **C3** refused with a new `listing_price_locked` (409) while the approval exists; spec 019's append-only, one-per-piece approval stays as it is.

## Out of scope

A customer-facing price history, a "price dropped" alert to savers (possible in spec 024), any limit on how often or how far, changing any other field of a live piece.

## Mock removed

`R.editprice` as a mock: the prototype screen with its fixed sample piece, the *Change fee* button's red flag, and `R.editprice` leaves `mockScreens`. The mock orders' "Change fee" actions in `lib/mock/mock_orders.dart` are left (they belong to the mock order screens).
