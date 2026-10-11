# Tasks: Change the price of a live piece (spec 022)

**Input**: `specs/022-live-listing-price-change/`: spec.md (D22-1…3, C1–C3), plan.md, research.md (R1–R14), data-model.md, contracts/listing-price-api.md, quickstart.md
**Repos**: `dahab-backend/` (B), `dahab-dashboard/` (D), `dahab-flutter/` (A). Branch `feature/live-listing-price-change` in each, created only when the owner asks.
**Tests**: required (Constitution V). Pest at the HTTP boundary on **`dahab_wt022` only**, with `DB_DATABASE/DB_USERNAME/DB_PASSWORD` passed explicitly, sequential. **One full run** (T040, in the background); targeted files before that; afterwards only failing files, reported to the owner. Never `migrate:fresh`, seeders or tests on the primary `dahab`.
**Every endpoint task**: `#[OA]`, the Postman request in the same task, Pest, and audit through `RecordAuditLogAction`. Codes and the audit event are added in Phase 2 with their catalogue tests and `docs/platform/api-contract.md`.
**Texts**: every new customer-visible sentence is a `TextKey` (`listing.price.*`) with the English as default and an Arabic **AR-DRAFT** default for the owner's review. Error messages from the Backend are staff/customer English as in `DomainApiException`.
**Git**: no commit or push unless the owner asks. No `Co-Authored-By`, no `Claude-Session`, no "Generated with", no AI/model name in any git message. Never change `user.name`/`user.email`.
**Line endings**: Dashboard and Flutter files mix CRLF and LF per file. Detect them in Python (`open(path, encoding='utf-8', newline='')`) and keep each file's endings; write helper scripts with the Write tool.

Format: `- [ ] T### [P?] [US?] description — repo` · `[P]` = parallel (different files, no dependency) · **[D]** Dashboard task · **[A]** Customer app task.

## Phase 1: Setup

- [ ] T001 Verify `dahab_wt022` and `dahab_wt022_dev` exist (`psql -l`); if either is missing, STOP and ask — never fall back to `dahab` — B
- [ ] T002 When asked: create `feature/live-listing-price-change` from `main` in B, D and A (`git fetch origin && git switch -c feature/live-listing-price-change main`). In a B worktree run `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` until it prints "Nothing to install", confirm `vendor/mpdf/mpdf/src/Mpdf.php` exists, never copy `vendor/`; copy the main `.env` — B, D, A
- [ ] T003 [P] Check `docs/features/live-listing-price-change.md` (written at planning) still matches; update its status to "in progress" — B

## Phase 2: Foundational (blocks all stories)

### Migration and schema (skill `laravel-migrations`)
- [ ] T004 `database/migrations/2026_10_15_000010_listing_price_change.php` per data-model §1 — B:
  - table `listing_price_change`: `change_id BIGSERIAL`, `listing_id UUID NOT NULL REFERENCES listing`, `field TEXT NOT NULL CHECK (field IN ('making_charge_per_g','asking_price'))`, `old_value`/`new_value NUMERIC(18,4) NOT NULL` with `>= 0 AND = round(…, 2)`, `CHECK (new_value <> old_value)`, `CHECK (field <> 'asking_price' OR new_value > 0)`, `actor_customer_id`/`actor_staff_id` with `CHECK (num_nonnulls(actor_customer_id, actor_staff_id) = 1)`, `changed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()`, `txid BIGINT NOT NULL DEFAULT txid_current()`; index `(listing_id, change_id)`;
  - forced RLS: `listing_price_change_isolation` (elevated or the caller's own listing; insert only as the caller with `actor_staff_id IS NULL`);
  - append-only trigger → DH023;
  - function `listing_price_locked(p_listing uuid) RETURNS boolean` (the `free_relist_origin_ok()` scope-switch pattern; `EXISTS market_maker_approval`);
  - `trg_listing_price_guard` (BEFORE UPDATE OF the two columns: state not in draft/changes_requested/live, state moved together with a live price, locked) and `trg_listing_price_recorded` (deferred: a live change needs its row in the same `txid`), both DH023;
  - `down()` refuses once a `listing_price_change` row exists, otherwise drops everything it created
- [ ] T005 [P] Mirror T004 in `docs/Database schema/04_schema_market.sql` (new "spec 022" section after the listing guards) and `00_schema_full.sql` — B
- [ ] T006 [P] `tests/Feature/Migrations/ListingPriceChangeMigrationTest.php`: columns and CHECKs; DH023 on update/delete of a history row; on a price `UPDATE` in `reserved`, `accepted`, `in_review`, `withdrawn`; on a live price `UPDATE` with no history row (at commit); on a live price `UPDATE` of an approved piece; on a price + state change in one `UPDATE`; a draft/changes-requested price `UPDATE` still passes with no row; `listing_price_locked()` from the customer scope; `down()` refuses with data and runs clean without. Then run the existing `tests/Feature/{Order,BuyRequest,Invoices,FreeRelist,MarketMakers,Listing}` suites once (targeted) to find factories/tests that write a price after the state moved; fix them by setting the price first, never by weakening the guard — B

### Models, enum, factory (skill `laravel-eloquent-models`)
- [ ] T007 [P] `app/Enums/ListingPriceField.php` (`making_charge_per_g`, `asking_price`, `for(PieceCategory)`); `app/Models/ListingPriceChange.php` (key `change_id`, no timestamps, casts `decimal:4`/`immutable_datetime`, `listing()`, `customer()`, `staff()`); `Listing::priceChanges()` ordered by `change_id`, `Listing::priceField()`, `Listing::currentPriceFigure()` — B
- [ ] T008 [P] `database/factories/ListingPriceChangeFactory.php`; reuse spec 019's way of creating a `market_maker_approval` in tests (add `ListingFactory::approvedForMarketMakers()` only if none exists) — B

### Codes, audit, mapping
- [ ] T009 [P] `DomainApiException`: `listingHasRequests()` 409 `listing_has_requests`, `listingPriceLocked()` 409 `listing_price_locked`, `listingNotLiveForPriceChange()` 409 `listing_not_live` ("Only a live piece's price can be changed."), `listingPriceHistoryImmutable()` 409 `listing_price_history_immutable` (messages in contracts §5); `bootstrap/app.php`: `DH023` in both SQLSTATE maps (message `market-maker` → locked, `append-only` → history immutable, else → has requests); `docs/platform/api-contract.md` error list — B
- [ ] T010 [P] `AuditEvent::LISTING_PRICE_CHANGED = 'listing.price_changed'`, label "Listing price changed", category `LISTINGS`; update `tests/Unit/Audit/AuditCatalogueTest.php` — B

**Checkpoint**: migration test green; targeted existing suites green.

## Phase 3: User Story 1 — The seller changes the figure on a live piece (P1) 🎯 MVP

**Goal**: a seller changes the making charge / asking price of their live piece; refused with the right code otherwise.
**Independent test**: `ChangeListingPriceTest` + quickstart §2 steps 1–7.

### Backend (skills `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-pest-testing`)
- [ ] T011 [US1] `app/Http/Requests/Customer/Listing/ChangeListingPriceRequest.php` with `#[OA\Schema('ChangeListingPriceRequest')]`: `listing()` = the caller's own (`seller_id` filter, 404 otherwise); `prepareForValidation()` refuses by state first (R2: `listing_has_requests` for reserved/accepted/at_inspection/settling/sold, `listing_not_live` for every other non-live state); rules: gold → `making_charge_per_g` `required`, `ListingRules::MONEY`, `gte:0`; else `asking_price` `required`, `ListingRules::MONEY`, `gt:0`; the other field `prohibited`; an `after()` check that the figure differs from the current one (`bccomp(…, 4)`), message "This is already the price." — B
- [ ] T012 [US1] `app/Actions/Listings/ChangeListingPriceAction.php` (`MovesListing::lockListing`): `Controls::assertPlatformRunning()` first; in one transaction lock the listing, re-check the state (R2 codes), `listing_price_locked()` → `listingPriceLocked()`, re-check the figure differs, update the one column, insert `listing_price_change` (seller actor, old/new), audit `listing.price_changed` (`actorCustomerId`, entity `listing`, `{listing_id, field, old_value, new_value}`); return the refreshed listing. No notification (D22-2) — B
- [ ] T013 [US1] `CustomerListingController@price` with `#[OA\Post('/customer/me/listings/{listing}/price')]` (request schema, `CustomerListing` answer, every refusal in contracts §1) and the route in `routes/api.php` inside the `customer.gate:trade` group: `->whereUuid('listing')->middleware(['throttle:customer.listings', 'idempotent'])->name('price')` — B
- [ ] T014 [US1] `can_change_price` on `app/Http/Resources/Customer/ListingResource.php` (+ `#[OA\Property]` and `required`): `state = live` and not locked; `ListOwnListingsAction` and the show path add `selectRaw('listing_price_locked(listing.listing_id) AS price_locked')` so the list stays one query — B
- [ ] T015 [US1] `tests/Feature/Listing/ChangeListingPriceTest.php`: gold happy path (figure, `current_price`/`you_would_receive` moved, history row, audit row, `state`/`state_changed_at`/`listed_at` unchanged, no notification sent, market `/market/listings/{id}` and the saver's `GET /customer/me/saved-pieces` show the new price); diamond and gold_with_diamond `asking_price`; same figure 422; wrong field 422 (`prohibited`); 3 decimals / negative / zero asking 422; reserved → `listing_has_requests`; in_review, changes_requested, suspended_hold, withdrawn → `listing_not_live`; approved for market makers → `listing_price_locked` and `can_change_price=false`; "stop everything" → `platform_stopped`, "pause category" and "stop new listings" → allowed; suspended seller → `account_suspended`; unverified → `verification_required`; another customer's listing → 404; missing `Idempotency-Key` and a replay → the middleware's answers; PATCH on a live piece still `listing_not_editable` — B
- [ ] T016 [US1] `tests/Feature/Listing/ListingPriceConcurrencyTest.php`: a buyer who confirmed the old price is refused `price_moved` after the change; a change after the buyer joined is `listing_has_requests` and the buyer's settlement figures are unchanged (`OrderSettlement::compute` with the old making charge); `can_change_price` in `OwnListingsTest` for live / reserved / approved — B
- [ ] T017 [US1] Postman: *Customer → Listings → Change price of a live piece* (`POST {{base_url}}/customer/me/listings/{{listing_id}}/price`, `Idempotency-Key: {{$guid}}`, gold body), per `postman/README.md`; no new environment variable unless `listing_id` is missing — B
- [ ] T018 [US1] `composer swagger:generate`; update `tests/Feature/Auth/Shared/OpenApiGenerationTest.php` and the route inventory in `tests/Feature/Auth/Shared/PrincipalIsolationTest.php` for the new route; run them — B

**Checkpoint**: T015–T018 green; quickstart §2 steps 1–7 by hand once the owner starts the API.

## Phase 4: User Story 2 — Staff can see it (P2)

**Goal**: the staff listing detail lists every price change with the actor and time.
**Independent test**: `ReviewQueueTest` detail case + the Dashboard detail.

- [ ] T019 [US2] `app/Http/Resources/Staff/ListingResource.php`: `priceHistory(Listing)` → `[{field, old_value, new_value, actor: {type, name}, changed_at}]` oldest first, `#[OA\Property('price_history')]` "Detail only"; `Dashboard/ListingController` detail adds `'price_history'` and eager-loads `priceChanges.customer` — B
- [ ] T020 [US2] Pest: in `tests/Feature/Listing/ReviewQueueTest.php` (or the detail test that covers `history`), the detail returns `price_history` after two changes, empty for none, absent from the list; readable with each of the three listing permissions; `composer swagger:generate`; the Postman detail request's description mentions `price_history` — B
- [ ] T021 [P] [US2] **[D]** `src/types/listing.ts`: `ListingPriceChange { field: 'making_charge_per_g' | 'asking_price', oldValue: Money, newValue: Money, actor: { type: 'customer' | 'staff', name: string | null }, changedAt: string }`, `ListingDetail.priceHistory: ListingPriceChange[]`; `src/services/listing.service.ts` maps `price_history` (default `[]`) — D
- [ ] T022 [US2] **[D]** `src/components/listings/ListingPriceHistory.vue` (new; the `ListingHistory.vue` style: "Making charge 250.00 → 220.00 /g" or "Asking price …", "Seller · <time>"), placed under *History* in `src/components/listings/ListingReviewPanel.vue` with the label *Price changes*, hidden when the list is empty — D
- [ ] T023 [US2] **[D]** `npm run type-check`, `npm run lint -- <touched files>`, `npm run build` — D

## Phase 5: User Story 1 in the Customer app (P1)

**Goal**: the real form replaces the prototype; the button shows only when the Backend allows it.
**Independent test**: `flutter test` (new flow test) + quickstart §3.

- [ ] T024 [P] [US1] **[A]** `lib/models/listing.dart`: `canChangePrice` from `can_change_price` (default `false`) — A
- [ ] T025 [P] [US1] **[A]** `lib/services/repositories.dart` `ListingsRepository.changePrice(String id, Map<String, dynamic> body, {required String idempotencyKey})`; `lib/services/api/listings_api.dart` posts to `/customer/me/listings/{id}/price`; any other implementer (`mock_repositories.dart`) gets the method — A
- [ ] T026 [US1] **[A]** `lib/core/text/keys/listing_price.dart` (new, `ListingPriceKeys`, prefix `listing.price.*`, area *Selling*, AR-DRAFT Arabic for each): screen title (gold "Change making charge" reuses `shared.change_making_charge`; stones "Change price"), the note ("You can change the price while no one has asked to buy the piece. The piece keeps its place and its views."), field labels (making charge per gram / asking price), "You would receive", "Save", "Price updated.", the errors for `listing_has_requests`, `listing_price_locked`, `listing_not_live`, `platform_stopped`, "This is already the price.", the button "Change price", the owner's-card line on a reserved piece ("Someone is in line for this piece, so its price can't change. You can change it again if the line empties."); register in `lib/core/text/keys/all_keys.dart` — A
- [ ] T027 [US1] **[A]** `EditPriceScreen` in `lib/features/orders/order_flows.dart` → `EditPriceScreen({required String listingId})`: loads `ListingsRepository.show`, shows the piece line from the real listing, the current figure, one 2-decimal field (the slider and the sample piece go), "You would receive" from `LiveRates.quote(QuoteParams…)` as in the sell flow, *Save* with `newIdempotencyKey()` → toast + back to the piece page refreshed; every error in plain words from the keys; `lib/routing/app_router.dart`: `R.editprice: (s) => EditPriceScreen(listingId: s.uri.queryParameters['id'] ?? '')` — A
- [ ] T028 [US1] **[A]** `lib/features/catalog/detail_screen.dart`: the owner's card shows *Change fee* (gold) / *Change price* (stones) when `own?.canChangePrice == true`, navigating with `?id=`; the `MockMark` and the `!d.live` condition go; when `own.state == reserved` the card shows the reserved-piece line from T026 (spec Story 1.2 "the piece page explains why"); `lib/widgets/mock_flag.dart`: `R.editprice` leaves `mockScreens` — A
- [ ] T029 [US1] **[A]** `python tool/gen_text_catalogue.py` (only if a literal moved), `UPDATE_TEXT_MANIFEST=1 flutter test test/text_manifest_test.dart`, then copy `assets/text/keys.json` to the Backend `database/data/app_text_keys.json` and run `php artisan app-texts:sync` against `dahab_wt022_dev` — A, B
- [ ] T030 [US1] **[A]** Tests: the fake backend in `test/test_app.dart` answers `POST /customer/me/listings/{id}/price` (200, and 409 codes on demand) and returns `can_change_price`; a flow in `test/flows_test.dart` (open own live piece → Change fee → type → Save → new price; reserved piece shows no button; a 409 `listing_has_requests` shows the text); `test/mock_flags_test.dart` and `test/routes_smoke_test.dart` follow the route change — A
- [ ] T031 [US1] **[A]** `flutter analyze`, `flutter test`, `flutter build web --release` — A

## Phase 6: Docs and verify

- [ ] T032 [P] Technical Spec `docs/Technical Spec/dahab-spec-part2-api.md` §3: "Changed by spec 022" beside `PATCH /customer/me/listings/{id}` — the new endpoint, its codes, `can_change_price`, `price_history` — B
- [ ] T033 [P] `docs/platform/api-contract.md`: the endpoint in the idempotent-writes list, the codes (T009), the listing shape notes — B
- [ ] T034 [P] `docs/features/listings.md` pointer to spec 022; `docs/features/live-listing-price-change.md` status "done" with the results — B
- [ ] T035 [P] `CLAUDE.md` current state: Backend (spec 022 sentence), Dashboard (*Price changes* on the listing detail), Flutter (*Change fee* live, `R.editprice` no longer mock) — B
- [ ] T036 `./vendor/bin/pint --test` on touched files (pre-existing Pint drift in `StartCustomerEmailVerificationAction` / `StartCustomerRegistrationAction` is not ours) — B
- [ ] T037 `laravel-quality-gates`: `migrate:fresh --seed` and rollback of the new migration on `dahab_wt022` (by explicit `DB_DATABASE`), `route:list --path=customer/me/listings`, N+1 check of the seller list, security review (RLS of the new table, no approval leak in messages) — B
- [ ] T038 Re-run T023 and T031 after the last change — D, A
- [ ] T039 `composer swagger:generate` final; Postman collection valid JSON — B
- [ ] T040 One full sequential Pest run on `dahab_wt022` **in the background** (`DB_DATABASE=dahab_wt022 … composer test > storage/logs/pest-022.log 2>&1`); then rerun only the failing files and report them to the owner (`InvoicesPerformanceTest` is timing-sensitive on a loaded machine) — B
- [ ] T041 Step 5 report (Part 1 shape) to the owner; no commit or push unless asked — B, D, A

## Dependencies

- Phase 1 → Phase 2 → Phase 3 (Backend US1) → Phase 5 (app US1); Phase 4 needs Phase 2 (and Phase 3 to produce data for the by-hand check); Phase 6 last.
- T004 blocks T005, T006, T012; T009/T010 block T012; T011–T013 block T015–T018; T019 blocks T020–T022; T024–T026 block T027–T028; T027–T028 block T030.

## Parallel opportunities

- Phase 2: T005, T006 (after T004), T007, T008, T009, T010.
- Phase 4 Dashboard (T021–T023) runs beside Phase 5 once the Backend shape is fixed.
- Phase 5: T024, T025, T026 together.
- Phase 6: T032–T035 together.

## Implementation strategy

MVP = Phases 1–3 (the Backend endpoint with its guard), then Phase 5 (the app) — that delivers the seller's feature. Phase 4 (staff read) is small and ships in the same release. Work through all phases without stopping between them; pause only for a real blocker.

## Counts

| Repo | Tasks |
|---|---|
| Backend only (B) | 27 (T001, T003–T020, T032–T037, T039, T040) |
| Dashboard only (D) | 3 (T021–T023) |
| Customer app only (A) | 7 (T024–T028, T030, T031) |
| Shared | 4 (T002 B+D+A, T029 A+B, T038 D+A, T041 all) |
| **Total** | **41** |
