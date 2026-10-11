# Spec 022 — Change the price of a live piece

**Status**: DRAFT for the owner's confirmation · **Repos**: Backend, Dashboard (read only), Customer app · **Priority**: independent of 020, 021, 023–025

## Why

The piece page has a red-flagged *Change fee* button and a prototype screen (`R.editprice`) with a fixed sample piece. The prototype promises: "You can change your making charge whenever no one has an open request on the piece. The listing keeps its history, so you don't lose the views it has built up." The Backend cannot do it: a listing is editable only as a draft or when it was sent back for changes (`ListingState::isEditable`).

## What exists today

- `PATCH /customer/me/listings/{id}` edits draft and changes-requested listings; reviewed listings re-enter review through the existing decisions.
- A buyer's price is locked at the request (spec 011); a live piece's displayed price comes from the pricing calculator, so only the seller's own figures (the making charge for gold, the asking price for stones and gold with diamond) can change.
- A live piece with no buyer in line is state `live`; once a buyer joins it is `reserved`.

## Scope

### Story 1 — The seller changes the figure on a live piece

1. **Given** a seller's own piece in state `live`, **When** they change the making charge (gold) or the asking price (stone, gold with diamond), **Then** the new figure takes effect at once on the market, Browse and saved pieces, and the previous figure is kept in the listing's history with who and when.
2. **Given** a buyer already in line (state `reserved` or later), **Then** the change is refused (`listing_has_requests`) and the piece page explains why; prices already locked are never touched.
3. The change is refused for a suspended seller, for a piece in review, and for a figure that fails the same validation as creation (range, decimals).
4. **[OPEN D22-1]** whether a change goes live straight away or re-enters review.
5. The seller sees the new "You would receive" figure before confirming (the live quote already exists), and the buyer-facing price is recalculated from the calculator, not typed.

### Story 2 — Staff can see it

The staff listing detail shows the history of price changes with times and the actor; no new staff action.

## Surfaces

| Repo | Change |
|---|---|
| Backend | an *edit price* action on a `live` listing (a new endpoint or `PATCH` for state `live` with a restricted field set), a price-history table with a guard, `listing_has_requests` code, an audit entry, a seller notice only if D22-2 says so, Postman, OpenAPI |
| Dashboard | the history block on the listing detail |
| Customer app | replace the prototype `EditPriceScreen` with a real form on the seller's own piece (current figure, slider or field, the live "You would receive"), remove the red flag and the sample piece, errors for `listing_has_requests` |

## Depends on / used by

Uses the pricing calculator and the listing history; independent of every other spec here. Spec 023's first-sale advance must not break the locked-price rule this spec relies on.

## Decisions needed from the owner

| ID | Question | Proposed |
|---|---|---|
| D22-1 | Does a price change on a live piece need review again? | No, while it only moves the seller's own figure within the same limits |
| D22-2 | Are the people who *saved* the piece told of a change? | No (Saved pieces "don't lock a price", spec 017) |
| D22-3 | Is there a limit on how often or how far a price moves? | None to start; the audit log shows abuse |

## Mock removed

`R.editprice`, the *Change fee* button's red flag and the sample piece; `R.editprice` leaves `mockScreens`.
