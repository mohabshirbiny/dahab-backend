# Research: Change the price of a live piece (spec 022)

Every decision below rests on the code at Backend `main` 598944f (spec 021 merged), Dashboard `main` and Customer app `main` (read 2026-10-11). The owner's answers D22-1…3 and C1–C3 are in `spec.md`.

## R1 — A new endpoint, not a wider `PATCH` (C1)

- **Decision**: `POST /customer/me/listings/{listing}/price`, trade gate, `idempotent`, `throttle:customer.listings`. Body: exactly one of `making_charge_per_g` (gold) or `asking_price` (diamond, gold with diamond). Answer `200` with the seller's `CustomerListing` shape.
- **Rationale**: `UpdateListingRequest::prepareForValidation` refuses every non-editable state with `listing_not_editable` before the rules run, and its rules cover media, branches and description. Keeping it untouched keeps the change non-breaking for the app's sell flow (`SellDraft` / `Fix and resend`) and keeps one rule per endpoint: PATCH edits drafts, `/price` moves a live figure.
- **Alternatives**: widening PATCH for `live` with a restricted field set (owner chose against: the 409 contract and the request's rules would depend on the state).

## R2 — Which states, which code

| Listing state | Answer |
|---|---|
| `live` | allowed (subject to R5, R6) |
| `reserved`, `accepted`, `at_inspection`, `settling`, `sold` | `409 listing_has_requests` — a buyer is in line or a price was locked |
| every other state (`draft`, `in_review`, `changes_requested`, `suspended_hold`, `withdrawn`, `rejected`, `uncollected_expired`, `awaiting_seller_return`, `seller_unclaimed`) | `409 listing_not_live` (existing code, spec 019; a new factory `listingNotLiveForPriceChange()` with the message "Only a live piece's price can be changed.") |
| not the caller's listing | `404` (RLS + `seller_id` filter, as `UpdateListingRequest::listing()`) |

The state is decided **before** validation (like PATCH), so a refused state never reports field errors. The Action re-checks under the row lock (R4).

## R3 — Validation: the same as creation

- `ListingRules::MONEY` (`numeric`, `decimal:0,2`, `max:99999999.99`) plus `gte:0` for the making charge and `gt:0` for the asking price — the creation rules, reused, not copied.
- The field that does not belong to the category is `prohibited`; sending neither, or both, is `validation_failed`.
- A figure equal to the current one is `validation_failed` (`errors.<field>`: "This is already the price."), so the history never holds a no-op row. Compared with `bccomp(…, 4)` against the stored `NUMERIC(18,4)`.
- **D22-3**: no cooldown and no cap on the move.

## R4 — Concurrency: the listing row lock

`ChangeListingPriceAction` runs one `DB::transaction` and `lockForUpdate()`s the listing (the `MovesListing::lockListing` helper). Every competing writer locks the same row first:

- `SendBuyRequestAction` (live → reserved) — a buyer who arrives first wins; the seller then gets `listing_has_requests`. A seller who arrives first wins; the buyer's confirmed price no longer matches and the existing tolerance check answers `409 price_moved` with the fresh price and deposit (spec 011). No buyer can be locked at a price they did not see beyond the tolerance.
- `ApproveForMarketMakersAction` (spec 019) — serialised with C3's check.
- `DecideListingAction` take-down, `WithdrawListingAction`, `HoldListingsOfCustomerAction` — the loser re-reads the state and is refused.

## R5 — Switches (C2)

`app(Controls::class)->assertPlatformRunning()` first, as `SendBuyRequestAction` and the withdrawals do: `409 platform_stopped` with the control's message under "stop everything". "Stop new listings" and "pause category" do not refuse a price change.

## R6 — Pieces approved for market makers (C3)

- **Decision**: while a `market_maker_approval` row exists for the listing, the change is refused with a new `409 listing_price_locked` ("This piece's price is fixed for now. Contact us if you need to change it."). The message does not name market makers; the seller is not told about the programme.
- **RLS**: `market_maker_approval` is readable only by elevated scopes and `queue`/`market` (migration `2026_10_12_000045`). The customer request runs in the `customer` scope, so it cannot read the row. Following `free_relist_origin_ok()` (spec 018), a SQL function `listing_price_locked(p_listing uuid) RETURNS boolean` switches `app.rls_scope` to `system` for its one `EXISTS` and restores it. The Action, the seller's Resource (`can_change_price`) and the guard (R7) all ask the same function; no customer scope is widened and no new policy is added to spec 019's table.
- **Alternatives**: running the Action in `DatabaseActor::queue` (that scope is reserved for the buy-request Actions and guarded by `QueueScopeTest`); a new RLS policy for the customer scope (would let a seller read approval rows).

## R7 — The database backstop (SQLSTATE DH023)

Settlement (`OrderSettlement::compute`) reads `listing.making_charge_per_g` / `asking_price` when the balance is paid; only the gold rate is locked on the buy request. Today nothing in the database stops those columns moving after a buyer is in line. New, without touching `listing_guard()` (redefined already by spec 018):

1. `trg_listing_price_guard` — `BEFORE UPDATE OF making_charge_per_g, asking_price ON listing`, when either value is distinct:
   - `OLD.state NOT IN ('draft','changes_requested','live')` → `DH023 'listing % price cannot change in state %'`;
   - `OLD.state = 'live' AND listing_price_locked(OLD.listing_id)` → `DH023 'listing % price is locked by a market-maker approval'`;
   - a state move in the same `UPDATE` as a live price change → `DH023` (a price change never moves the state).
2. `trg_listing_price_recorded` — `AFTER UPDATE OF making_charge_per_g, asking_price`, `DEFERRABLE INITIALLY DEFERRED`: a change made while `OLD.state = 'live'` must have its `listing_price_change` row in the same transaction (`txid`), as `trg_listing_change_recorded` does for states.
3. `listing_price_change` is append-only: `UPDATE`/`DELETE` → `DH023 'listing price history is append-only'`.

Draft and changes-requested edits through PATCH are not recorded (the piece has never been bought at that figure and review sees the final one). `bootstrap/app.php` maps DH023 by message: `market-maker` → `listing_price_locked`; `append-only` → `listing_price_history_immutable` (409); otherwise → `listing_has_requests`.

## R8 — History and audit

- `listing_price_change` (data-model §1) keeps field, old and new figure, the actor, `changed_at`, `txid`.
- Audit: new `AuditEvent::LISTING_PRICE_CHANGED = 'listing.price_changed'`, category `LISTINGS`, label "Listing price changed", actor the seller (`actorCustomerId`), entity `listing`, payload `{listing_id, field, old_value, new_value}`. Not a founder-alert event (spec 021).
- No notice to the seller, the savers (D22-2) or the buyers (there are none in `live`). Nothing changes in `customer_notification`.

## R9 — What the seller sees

- `CustomerListing` gains `can_change_price` (boolean): `state = live` **and** not `listing_price_locked()`. The list endpoint computes it with one `selectRaw('listing_price_locked(listing.listing_id) AS price_locked')` column so the list stays one query (N+1 check in the quality gates).
- The form previews "You would receive" with the existing public `GET /reference/quote` (spec 015). The answer of `/price` carries the listing's new `current_price` and `you_would_receive` from `ListingPricer`, the figures that count.
- No customer-facing price history (out of scope).

## R10 — What staff see (Story 2)

The staff detail (`GET /dashboard/listings/{listing}`) gains `price_history`: oldest first, `{field, old_value, new_value, actor: {type: 'customer', name}, changed_at}`. Eager-loaded with the detail (one query). No new permission: any listing permission reads it, as the rest of the detail. The list shape is unchanged.

## R11 — Customer app

- `R.editprice` becomes a real screen with `?id=<listing id>` (the `R.order` pattern), opened by *Change fee* (gold) / *Change price* (stones) on the seller's own piece when `can_change_price` is true; the `MockMark` and the `!d.live` condition go, `R.editprice` leaves `mockScreens`.
- The form: the current figure, one field (a number field with 2 decimals; the prototype's slider is dropped because stones have no sensible range), the live "You would receive" from `LiveRates.quote(QuoteParams…)` (the sell flow's), *Save*. Errors: `listing_has_requests`, `listing_price_locked`, `listing_not_live`, `platform_stopped`, `validation_failed`, `account_suspended` in plain words.
- Texts: a new keys file `lib/core/text/keys/listing_price.dart`, prefix `listing.price.*`, English the code's own, Arabic **AR-DRAFT** for the owner's review; registered in `all_keys.dart`; the manifest regenerated (`UPDATE_TEXT_MANIFEST=1 flutter test test/text_manifest_test.dart`) and copied to the Backend `database/data/app_text_keys.json`, then `php artisan app-texts:sync`. Keys are append-only; the prototype literal strings that already have keys stay in the catalogue.
- The fake backend in `test/test_app.dart` gains the `/price` route; `test/mock_flags_test.dart` follows `mockScreens`.

## R12 — Dashboard

`src/types/listing.ts` (`ListingPriceChange`, `ListingDetail.priceHistory`), `src/services/listing.service.ts` (map `price_history`), a new `src/components/listings/ListingPriceHistory.vue` under *History* in `ListingReviewPanel.vue` (hidden when empty), `src/mock` only if the mock layer requires it (it has no listing mock today). No action, no permission constant.

## R13 — Documentation (Constitution III)

Technical Spec Part 2 §3 (listings: the new endpoint and codes beside `PATCH /customer/me/listings/{id}`), `docs/Database schema/04_schema_market.sql` and `00_schema_full.sql` (table, triggers, function), `docs/platform/api-contract.md` (error codes, the endpoint in the idempotent list), `docs/features/listings.md` (pointer), `CLAUDE.md` current state, `docs/features/live-listing-price-change.md`.

## R14 — Migration

One migration `database/migrations/2026_10_15_000010_listing_price_change.php` (after spec 021's `2026_10_14_000010`). `down()` refuses once a `listing_price_change` row exists (like spec 021), otherwise drops the triggers, the function and the table.
