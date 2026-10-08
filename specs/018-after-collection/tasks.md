# Tasks: After collection — free relist and rating

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/after-collection-api.md](./contracts/after-collection-api.md), [quickstart.md](./quickstart.md)

**Status**: written 2026-10-08; **nothing started**. No branch exists. The implementation session creates `feature/after-collection` in each repo from `main` (Backend `9cb3fe1`, Dashboard `f73065f`, Flutter `88539b6` — re-check the SHAs first, T001).

**Tests**: required (Constitution V). Every state-changing path is tested through the HTTP boundary and asserts persisted rows, ledger lines, notifications and audit entries. Concurrency (double relist, double rating) and reconciliation (waived settlement vs ledger vs invoices) are explicit tasks.

**Database rules**
- Tests run on **`dahab_wt018`**; the seeded app on **`dahab_wt018_dev`**; both owned by `dahab`, created by the owner (`CREATE DATABASE dahab_wt018 OWNER dahab;`).
- Pass `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=…` explicitly. **Never** run the suite, `migrate:fresh`, a rollback or a seeder against the main `dahab` database.
- Run the suite sequentially. Tests that commit data put back every kept table they change (settings). New test helpers get names unique across the suite (prefix `ac018…`).

**Paths**: Backend relative to `dahab-backend/`; Dashboard to `dahab-dashboard/`; Flutter to `dahab-flutter/`. Match each file's existing line endings. Pint only on changed files; `dart format --line-length 180` only on changed files.

**Git**: never commit or push unless the owner says so; stage only changed files (never `git add -A`); `git status` right before every commit; same branch name in all three repos.

**Every endpoint task** includes its `#[OA\…]` attributes, its Postman request in `postman/Dahab-Backend.postman_collection.json` (folders "Customer orders" and "Orders"; body in step with the FormRequest; `Idempotency-Key` pre-request script), and the OpenAPI regeneration (`composer swagger:generate`).

## Format: `[ID] [P?] [Story] Description`

Stories (spec.md): **US1** offer at handover · **US2** the free relist · **US3** 0% sale and buyer-only invoice · **US4** rating · **US5** staff see both. `[P]` = can run in parallel (different files, no dependency).

---

## Phase 1: Setup (docs first — Constitution III)

- [ ] T001 Verify the three `main` SHAs against GitHub; create `feature/after-collection` in each repo; confirm `dahab_wt018` and `dahab_wt018_dev` exist (owner `dahab`); run the current Backend suite on `dahab_wt018` and confirm it is green; confirm the Dashboard and Flutter trees are clean.
- [ ] T002 [P] Update `docs/features/after-collection.md` with the plan's file lists and the decisions (Q1–Q19) as recorded; keep "Status".
- [ ] T003 [P] Update the schema docs as designed in `data-model.md`: `docs/Database schema/00_schema_full.sql`, `04_schema_market.sql` (`collection.free_relist_until`, `listing.relisted_from_order_id`, `order_rating`), `05_schema_security.sql` (guard, `listing_transition` row, RLS, DH016, DH012 invoice check).
- [ ] T004 [P] Amend the Technical Spec, each change marked **"Changed by spec 018"** with a link to `specs/018-after-collection/spec.md`: Part 1 §4.2/§5.1 (`rating.view`; RLS on `order_rating`), Part 2 (customer orders + the two new endpoints; dashboard orders; handover note; invoices: no seller invoice on a waived sale), Part 3 §1.3 (the free-relist window is working hours), §2.5 (waiver: commission, its VAT and the minimum are 0; spread still applies), §3 (settlement of a waived sale), §10 (the afterlife of a collected piece).
- [ ] T005 [P] Update `docs/platform/api-contract.md`: the two POSTs, the additive blocks, the filter, the five codes, the idempotency list (+ free-relist, rating).

---

## Phase 2: Foundational (blocks every story)

- [ ] T006 Write `tests/Feature/FreeRelist/FreeRelistSchemaTest.php` (fails first): `collection_free_relist_shape` CHECK; write-once `free_relist_until`; `listing.relisted_from_order_id` immutable and partial-unique; `draft → live` refused for a listing without the link and allowed with it; the INSERT guard refuses a link whose origin order is not `completed`, whose buyer is not the seller, or whose window has passed, or whose origin listing is itself linked; `order_rating` table, unique (order, party), 1–5, note ≤ 500, UPDATE/DELETE → DH016, party/customer match, no rating for an unsettled or cancelled order, `not_closed` trigger; forced RLS on `order_rating`.
- [ ] T007 Write the migration `database/migrations/2026_10_11_000010_after_collection.php` (reversible, pgsql only, LF): the columns, CHECKs, partial unique index, `listing_transition` row, `listing_guard` changes, `order_rating` + triggers + RLS, the DH012 waived⇒no-seller-invoice trigger. Seed `rating.view` to CEO and COO the way `listing_report.handle` was seeded in `customer_account.php`.
- [ ] T008 [P] Models and enums: `app/Models/OrderRating.php` (+ factory), `Listing` (`relisted_from_order_id`, `isFreeRelist()`, relation `relistedFrom`), `Collection` (cast `free_relist_until`), `Order` (`ratings()`); `StaffPermission::RATING_VIEW` (+label, group, default roles); `AuditEvent::ORDER_FREE_RELISTED`, `ORDER_RATED` (+labels, customer-visible/staff lists); `OrderEvent::FREE_RELISTED`; five `DomainApiException` factories (contract). Reuse the existing `PartyRole` enum for the rating's party (no new enum).
- [ ] T009 [P] `app/Support/Orders/FreeRelistOffer.php` (status none|open|used|expired, research R10) and `DeadlinePolicy::freeRelistEnd()` (research R2) — unit tests in `tests/Unit/Orders/FreeRelistOfferTest.php` (boundaries: `now == end`, used, none). Update the `DeadlinePolicy` class docblock: it is no longer "only the reach-branch deadline is working hours" (the free-relist window is too).
- [ ] T010 Test helpers `tests/Support/FreeRelists.php` (`ac018CollectedOrder()`, `ac018RelistPayload()`, `ac018SettleRelisted()`); run T006 until green.
- [ ] T011 Extend the permission and route inventories: `tests/Feature/Authorization/PermissionCatalogueTest.php` (+ `rating.view`, CEO and COO only), `tests/Feature/Order/OrderPermissionsTest.php`; confirm `CustomerRouteGateTest` passes once the routes exist (T018, T034).

**Checkpoint**: schema green; the stories can start.

---

## Phase 3: US1 — The offer is made at handover (P1) 🎯 MVP

- [ ] T012 [US1] Tests first `tests/Feature/FreeRelist/OfferAtHandoverTest.php`: stored end = handover + 12 working hours on the order's branch (weekday; Thursday evening → Sunday; a `branch_closure`; an all-branch holiday); proxy handover stores it too; setting 0 → none; no branch hours → handover succeeds, none, audit context says `hours_unavailable`; a free-relist sale's handover stores none; a later setting change does not move the end; the order read shows `free_relist.status open` with `ends_at`; the buyer's `COLLECTED` notice mentions the end, the seller's does not. Also: a late handover (listing `uncollected_expired` → `sold`) starts the window at that handover; the offer stays visible and keeps counting while the buyer is suspended; no reminder or expiry notification is ever sent (assert none queued).
- [ ] T013 [US1] Edit `app/Actions/Orders/Staff/HandoverPieceAction.php`: compute and store `free_relist_until` in the handover transaction (R2/R3), add the audit context keys (R2), pass the end to the buyer's `tellOrder(... deadline:)`; update `OrderNotification::body()` (EN/AR) for `COLLECTED` with a deadline.
- [ ] T014 [US1] `CustomerOrderResource` + `ListOwnOrdersAction`: add the `free_relist` block (contract) and `rating` placeholder shape; eager-load without N+1 (one extra existence query per list page).
- [ ] T015 [US1] Postman: update the handover request notes and the customer-order response examples; OA examples on `OrderController::show/index`.

## Phase 4: US2 — The free relist (P1)

- [ ] T016 [US2] Tests first `tests/Feature/FreeRelist/FreeRelistTest.php`: happy gold / diamond / gold-with-diamond (every copied field, entered fields, declaration row, history rows with the note, `listed_at`, audit, notification to SMS + email + inbox with a link to the new listing); IGI-measured karat/weight win over stated; private invoice not copied; each refusal (not the buyer 404, other customer 404, not completed 409, expired, already relisted, suspended 403, closed 403, branch options all disabled 422, stale declaration 422, missing price per category 422); origin listing/order/history unchanged; ordinary draft still cannot go live; offer becomes `used`. Also assert the throttle: the relist limiter answers 429 after its limit.
- [ ] T017 [US2] `app/Actions/Orders/Customer/FreeRelistAction.php` (R4–R7, R14, R15): `DatabaseActor::order` transaction; lock listing → order → collection; checks; INSERT draft with link; copy media/branches; ownership declaration; `draft → live` via `MovesListing` with the note; audit; outbox; `flushOrderOutbox()` after commit.
- [ ] T018 [US2] `FreeRelistRequest`, `OrderController::freeRelist` (+ `#[OA]`), route in the `customer.gate:trade` group with `idempotent` and throttle (R11); `ListingResource` (customer + staff) exposes `relisted_from_order`.
- [ ] T019 [US2] Concurrency test `tests/Feature/FreeRelist/FreeRelistConcurrencyTest.php`: same key replay; two keys sequential → `already_relisted`; two parallel requests (two DB connections) → exactly one listing; a relist racing the handover of another order does not deadlock (lock order).
- [ ] T020 [US2] Isolation test `tests/Feature/FreeRelist/FreeRelistIsolationTest.php`: another customer (and the origin's seller) cannot read the buyer's offer, call the relist, or read the new listing through the customer endpoints; the public market view of the relisted listing exposes no seller identity or private media, exactly like any live listing; a staff member without `order.view` cannot read the offer.
- [ ] T021 [US2] Postman: request "Free relist" (folder "Customer orders", body from `FreeRelistRequest`, saved ids); `composer swagger:generate`.

## Phase 5: US3 — 0% sale and buyer-only invoice (P1)

- [ ] T022 [US3] Tests first `tests/Feature/FreeRelist/WaivedSettlementTest.php`: gold and stone relisted listing → `commission_amount = 0`, `vat_amount = 0`, spread equal to the normal rule, `seller_proceeds = seller_gross`, ledger entry balanced and **without** commission/VAT lines, buyer total unchanged; a non-relisted listing is unchanged (regression); the minimum commission is not applied; a failed inspection follows spec 012 and does not restore the offer; the sale's own order gives no offer; origin sale's ledger, invoices and credit-note ability unchanged. Also: (a) a sale of the relisted listing that falls through (`settling → live`, e.g. declined adjustment) and sells again is still 0%; (b) after the relisted listing is withdrawn, a new listing of the same piece settles with the ordinary commission; (c) no ledger line or entry exists for an inspection (the free second inspection posts nothing).
- [ ] T023 [US3] `ListingPricer::piece()` and the settlement path pass `commissionWaived: $listing->isFreeRelist()` (R8); the payout estimate shown on `GET /customer/me/listings` for a relisted listing shows commission 0 and the spread; check `ListingQuote`/`QuoteAction` callers.
- [ ] T024 [US3] `IssueTaxInvoices`: skip the `-S` invoice when the commission is zero (R9); `CustomerOrderResource`: seller `invoice: null`, `no_fee: true`; `InvoiceLines`/documents unaffected.
- [ ] T025 [US3] Tests `tests/Feature/Invoices/WaivedInvoiceTest.php`: only `-B` exists; the DH012 trigger refuses a seller invoice on a waived order and a missing one on an ordinary order; the buyer's PDF job runs; the Dashboard invoices list shows one row for the order; no credit note possible; extend `InvoiceReconciliationTest`.
- [ ] T026 [US3] Extend `tests/Feature/Order/SettlementMathTest.php` and `StoneSettlementTest.php` with a waived case; extend `OrderReconciliationTest` (ledger vs order columns).

## Phase 6: US4 — Rating (P2)

- [ ] T027 [US4] Tests first `tests/Feature/Rating/RateOrderTest.php`: seller can rate from `ready_to_collect`, buyer only when `completed`; stars required 1–5; note ≤ 500 and empty → null; second submit same key replays, other key `already_rated`; 30-day close (travel with `travelTo`) → `rating_closed`; cancelled orders (including a dispute against the sale) → `rating_not_available`, an earlier rating stays; suspended may rate; closed → `account_closed`; the counterparty and strangers get 404; no notification sent; no audit note text; no change to suspension, flags or any other table.
- [ ] T028 [US4] `app/Actions/Orders/Customer/RateOrderAction.php` (R12): `DatabaseActor::order`, lock order, window math from `order_state_change` / `completed_at`, insert, audit `order.rated`; `RateOrderRequest`; `OrderController::rate` (+ `#[OA]`); route in a `customer.gate:verified` group with `idempotent` + `customer.ratings` limiter (register in `AppServiceProvider`). Add a test that the rating limiter answers 429 after 10 requests a minute.
- [ ] T029 [US4] `CustomerOrderResource` `rating` block filled (own party only; `can_rate`, `opens_at`, `closes_at`, `given`).
- [ ] T030 [US4] `tests/Feature/Rating/RatingConcurrencyTest.php` (two parallel submits → one row) and `RatingIsolationTest.php` (RLS: another customer cannot read any row; direct UPDATE/DELETE → DH016; forced RLS present).
- [ ] T031 [US4] Postman: request "Rate an order"; `composer swagger:generate`.

## Phase 7: US5 — Staff see both (P2, Backend)

- [ ] T032 [US5] Tests first `tests/Feature/FreeRelist/StaffViewTest.php` and `tests/Feature/Rating/StaffRatingsTest.php`: `GET /dashboard/orders/{id}` shows `free_relist` and `relisted_from_order` with `order.view`; `ratings` appears only with `rating.view` (omitted otherwise, never `[]`); list filter `free_relist=open|used|expired`; listings list/detail show `relisted_from_order`; customer-file activity shows `order.free_relisted`, and `order.rated` only with `rating.view`; no staff endpoint can create, change or delete an offer or a rating (404/405 asserted).
- [ ] T033 [US5] Edit `ListOrdersAction` (filter + `free_relist_status` per row), `ShowOrderAction`/`StaffOrderResource` (blocks), staff `ListingResource` (`relisted_from_order`), `ListCustomerActivityAction` + audit presenter (permission-aware). `ExportOrdersAction` calls `ListOrdersAction::filtered(...)`; add the `free_relist` parameter to that method and pass it from the export, with a CSV test that the export honours the filter.
- [ ] T034 [US5] Postman: update the dashboard orders requests (query `free_relist`, response samples); `composer swagger:generate`; regenerate and diff `storage/api-docs/api-docs.json` for the two new paths and fields.
- [ ] T035 [US5] Seed local demo data (`database/seeders/LocalAfterCollectionSeeder.php`, wired in `DatabaseSeeder`): a collected order with an open offer, an expired one, a relisted one, and ratings from both parties — dev DB only.

**Backend gates (end of Phase 7)**: `composer test` green on `dahab_wt018`; `./vendor/bin/pint --test`; `migrate:fresh --seed` on `dahab_wt018_dev`; rollback of the new migration; `composer swagger:generate`; route list shows only the two new routes.

---

## Phase 8: Dashboard (US5)

- [ ] T036 [P] Types and constants: `src/types/order.ts` (`FreeRelist`, `OrderRating`, row field `freeRelistStatus`, `relistedFromOrder`), `src/types/staff.ts` (`ratingView: 'rating.view'` in the permission map and the union), `src/types/listing.ts` (`relistedFromOrder`), `src/api/endpoints.ts` (query only; no new path).
- [ ] T037 Services and composables: `src/services/order.service.ts` (map snake → camel, filter param `free_relist`), `src/composables/useOrders.ts` (filter in query key), `src/services/listing.service.ts`, `src/composables/useCustomerFile.ts` (event labels).
- [ ] T038 [P] Components: `src/components/orders/FreeRelistPanel.vue` (status, window end, link to the listing — read-only), `OrderRatingsPanel.vue` (stars, note, date, both parties; rendered only with `rating.view`); mount both in `OrderDetailPanel.vue`; the timeline shows the relist event. Also show, on the relisted listing's own order, a line "This sale is a free relist of order DH-… (no commission)" from `relistedFromOrder`.
- [ ] T039 Orders list: `src/pages/orders/index.vue` + `OrdersTable.vue` — *Free relist* filter and badge; keep the existing groups and permission gating.
- [ ] T040 [P] Listings: the "Free relist of order …" line in `src/components/listings/ListingHistory.vue` and the listing detail in `src/pages/listings/index.vue`.
- [ ] T041 [P] Customer file: `src/pages/customers/[id].vue` shows the new history events with English/Arabic labels; ratings events hidden without `rating.view`.
- [ ] T042 [P] i18n strings (EN/AR) for every new label, filter option and empty state; permission label "View ratings" in the roles screen source (verify where permission labels live, then add).
- [ ] T043 Tests and gates: component tests for the two panels and the filter (follow the existing test style in the repo); `npm run type-check`, `npm run lint`, `npm run build`. Include a test for that line.

## Phase 9: Customer App (US2, US3, US4)

- [ ] T044 [P] Models: `lib/models/order.dart` (`FreeRelistOffer`, `OrderRating`, `noFee`, parse from the order JSON; unknown status falls back to `none`).
- [ ] T045 Services: `lib/services/api/orders_api.dart` (`freeRelist(...)`, `rate(...)`, both with `Idempotency-Key`), `lib/services/repositories.dart` (+ interface), `lib/services/mock_repositories.dart` (throw `UnsupportedError` like the other live-only calls).
- [ ] T046 Order screen: `lib/features/orders/order_screen.dart` — `FreeRelistCard` on a completed order for the buyer: status `open` shows the explanation, the countdown (computed from `ends_at`, ticking while visible, ends at 00:00 with "time is up" and the card disappears on refresh), button *Relist this piece, 0% commission*; `used` shows "Back on the market" with a link; `expired`/`none` shows nothing. A suspended buyer sees the card with the button disabled and the reason.
- [ ] T047 Relist form (new screen/sheet in `lib/features/orders/order_flows.dart` or a new `free_relist_screen.dart`): price field per category (making charge per gram / asking price), description prefilled, the current ownership declaration with its text, an estimate line that says the buy/sell difference still applies (EN/AR copy approved by the owner, A6), confirm; map the errors (`free_relist_expired`, `already_relisted`, `account_suspended`, `branch_options_required`) to plain messages; success → My listings.
- [ ] T048 Seller view: show "No Dahab fee on this sale" instead of an invoice when `noFee` is true (order screen invoice area, `lib/features/orders/order_screen.dart`).
- [ ] T049 `RateScreen` (`lib/features/orders/order_flows.dart`): live — five stars, optional note (500), Send, thank-you; closed and already-given states; **no invite card**; entry from the order screen when `rating.canRate`; remove `R.rate` from `mockScreens` in `lib/widgets/mock_flag.dart`; keep the route in `lib/routing/{routes.dart,app_router.dart}` (add the order id argument).
- [ ] T050 Delete the mock relist timer from `lib/services/app_session.dart` (`_relistTimer`, `_relistMinutes`, `relistMinutesLeft`) and any caller; confirm `grep relistMinutes` is empty.
- [ ] T051 i18n: add EN and AR strings (`lib/core/i18n/i18n.dart`) for every string above; `test/i18n_order_help_test.dart` style check that no string is missing in AR.
- [ ] T052 Tests: extend the fake backend in `test/flows_test.dart` with the two endpoints and the order blocks; new `test/free_relist_test.dart` (offer open → relist → My listings; expired; suspended; errors), a rating flow test, update `test/mock_flags_test.dart` (R.rate no longer mock) and `test/routes_smoke_test.dart`.
- [ ] T053 Gates: `flutter analyze`, `flutter test`, `flutter build web --release`.

## Phase 10: Polish & cross-cutting

- [ ] T054 Run the quickstart scenarios 1–5 by hand against `dahab_wt018_dev` with the three apps; fix findings.
- [ ] T055 `CLAUDE.md` "Current state": add the free relist, the waiver and the rating (Backend, Dashboard, Flutter) and what is still mock (nothing from this spec); update `docs/features/after-collection.md` Status to done; update Technical Spec "Changed by spec 018" links.
- [ ] T056 `/speckit-analyze` across spec, plan and tasks; resolve every finding or record it.
- [ ] T057 Final gates in all three repos; Step 5 report (Platform shape in `CLAUDE.md`): list of projects not changed (none), classification (non-breaking), Finance sign-off status for the buyer-only invoice, the Terms wording status.

---

## Dependencies & execution order

- Phase 1 → Phase 2 (T006–T011) → stories. US1 → US2 → US3 (a waived sale needs a relisted listing); US4 and US5 depend only on Phase 2 (+ US1 for the offer block in US5).
- Backend Phases 3–7 finish before the Dashboard (Phase 8) and Customer App (Phase 9); 8 and 9 are independent of each other.
- Inside a phase: tests first (they fail), then the Action/Resource, then Postman/OA.

## Parallel examples

- After T007: T008, T009 in parallel.
- Phase 8: T036, T038, T040, T041, T042 are different files.
- Phase 9: T044 and T051 can start together; T046–T049 share `order_screen.dart`/`order_flows.dart` — sequential.

## Implementation strategy

1. **MVP** = Phases 1–5 (offer, relist, 0% sale, buyer-only invoice) on the Backend, then the Customer App offer card and relist form (T044–T048) — the owner-visible promise.
2. Rating (Phase 6) and staff reads (Phase 7) next; Dashboard (Phase 8); rating screen (T049); polish.
3. Stop and report at each backend checkpoint; the implementation session decides commits (never without the owner's word).

## Open items that block nothing but must be closed before release

- Finance sign-off on "no seller invoice for a 0% sale" (spec FR-021).
- Owner approval of the estimate wording in English and Arabic (A6).
- Terms §5.5 wording review (legal).
