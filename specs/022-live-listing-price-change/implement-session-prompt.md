# Prompt for the local session: implement spec 022 (Change the price of a live piece)

Implement spec 022 on the owner's machine. The plan is finished and approved; this session builds it. Work through `tasks.md` in order, all six phases, to the end.

## 0. State you start from

- Planning is committed and pushed on the Backend branch **`claude/sleepy-ptolemy-686iu8`** (Backend `main` 598944f + one docs commit). `main` does not have the spec 022 files yet. The files are in `specs/022-live-listing-price-change/` and `docs/features/live-listing-price-change.md`.
- Files: `specs/022-live-listing-price-change/{spec,plan,research,data-model,quickstart,tasks}.md` and `contracts/listing-price-api.md`. These are **all confirmed** by the owner (2026-10-11); do not reopen them:
  - D22-1: no new review;
  - D22-2: no notice to savers, nor to the seller;
  - D22-3: no limit on how often or how far;
  - C1: a new `POST /customer/me/listings/{listing}/price`, with PATCH unchanged;
  - C2: refused only under "stop everything" (`platform_stopped`);
  - C3: refused while the piece is approved for market makers (`listing_price_locked`).
- Create the branch `feature/live-listing-price-change` in **all three repos**:
  - Backend (`D:\laragon\www\dahab-backend`): `git fetch origin && git switch -c feature/live-listing-price-change origin/claude/sleepy-ptolemy-686iu8` (it is `main` 598944f, with spec 021 merged, plus the planning commit).
  - Dashboard (`..\dahab-dashboard`): from `main` (613a753 or later).
  - Customer app (`..\dahab-flutter`): from `main` (2417eab or later).
  - In a Backend worktree, run `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` until it prints "Nothing to install", and confirm `vendor/mpdf/mpdf/src/Mpdf.php` exists. **Never copy `vendor/` from another checkout.** Copy the main `.env`.
- **Databases first (T001).**
  - The owner creates `dahab_wt022` (suite) and `dahab_wt022_dev` (dev data); the `dahab` user has no CREATEDB. Check with `psql -l`. If either is missing, STOP and ask.
  - **Never touch the primary `dahab` database**: no tests, no `migrate:fresh`, no seeders, no `app-texts:sync` there.
  - Pass `DB_DATABASE/DB_USERNAME/DB_PASSWORD` on the command line every time.
- Use PHP 8.4.
- Servers: the owner starts the API (`127.0.0.1:8000`), the Dashboard (`localhost:3000`) and the app. Do not leave any running.

## 1. Read first

1. `CLAUDE.md` (Part 1 platform rules, Part 2 Backend rules) and `docs/platform/*.md`.
2. The spec 022 files:
   - `tasks.md` is the work list (Backend tasks unmarked; **[D]** Dashboard; **[A]** Customer app);
   - `research.md` R1–R14 holds every design decision;
   - `contracts/listing-price-api.md` has the exact API and codes;
   - `data-model.md` has the migration, CHECKs, the function and the DH023 triggers.
3. The built code the plan builds on:
   - Backend:
     - `ListingState`, `UpdateListingAction`, `UpdateListingRequest`, `ListingRules`;
     - `MovesListing` (`lockListing`), `CreateListingAction`;
     - customer and staff `ListingResource`, `ListOwnListingsAction`, `Dashboard\ListingController` (detail `history`);
     - `ListingPricer`, `QuoteAction` (`/reference/quote`), `OrderSettlement::compute` (reads the listing's figure at payment);
     - `SendBuyRequestAction` (lock, `price_moved`), `ApproveForMarketMakersAction`, `PromoCodeGate`;
     - `Controls::assertPlatformRunning`, `RecordAuditLogAction`;
     - the guards in `2026_10_03_000020_create_listings.php` and `2026_10_11_000010_after_collection.php` (`free_relist_origin_ok()` is the scope-switch pattern);
     - the `market_maker_approval` policy in `2026_10_12_000045_market_maker_purchases.php`.
   - Dashboard: `src/types/listing.ts`, `src/services/listing.service.ts`, `src/components/listings/{ListingReviewPanel,ListingHistory}.vue`.
   - Customer app:
     - `lib/features/orders/order_flows.dart` (`EditPriceScreen`, the prototype);
     - `lib/features/catalog/detail_screen.dart` (the *Change fee* `MockMark`);
     - `lib/widgets/mock_flag.dart` (`mockScreens`), `lib/routing/{routes,app_router}.dart`;
     - `lib/models/listing.dart`, `lib/services/{repositories.dart,api/listings_api.dart,sell_draft.dart,live_rates.dart}` (the sell flow's live quote);
     - `lib/core/text/keys/` (`orders_after_collection.dart` and `auth_reset.dart` are the pattern for a hand-written keys file with AR-DRAFT);
     - `tool/gen_text_catalogue.py`, `test/test_app.dart` (fake backend), `test/flows_test.dart`, `test/mock_flags_test.dart`.
4. Skills: `laravel-migrations`, `laravel-eloquent-models`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-pest-testing`, `laravel-quality-gates`.

## 2. What to build (summary; the files win)

- **Backend endpoint.** `POST /customer/me/listings/{listing}/price`:
  - trade gate, `throttle:customer.listings`, `idempotent`;
  - body: `making_charge_per_g` (gold) or `asking_price` (diamond, gold with diamond), the creation rules (`ListingRules::MONEY`), and it must differ from the current figure;
  - answers `200 CustomerListing`.
- **Refusals**, in this order:
  - `platform_stopped`;
  - `listing_has_requests` (reserved / accepted / at_inspection / settling / sold);
  - `listing_not_live` (any other non-live state; reuse the code with the message "Only a live piece's price can be changed.");
  - `listing_price_locked` (market-maker approval);
  - `validation_failed`.
- **The change itself.**
  - No review and no state move: `state_changed_at` and `listed_at` stay as they are.
  - One `listing_price_change` row and audit `listing.price_changed`, with the seller as actor.
  - No notification of any kind.
- **Additive fields.** `can_change_price` on the seller's listing, computed in one query for the list. `price_history` on the staff listing detail.
- **One migration**, `2026_10_15_000010_listing_price_change.php`:
  - the table (append-only, forced RLS);
  - `listing_price_locked(uuid)` (system scope switch, boolean only);
  - `trg_listing_price_guard` and the deferred `trg_listing_price_recorded` (DH023);
  - `down()` refuses once history exists;
  - mirrored in `04_schema_market.sql` / `00_schema_full.sql`;
  - `bootstrap/app.php` maps DH023.
- **Dashboard.** *Price changes* (`ListingPriceHistory.vue`) under *History* on the listing detail.
- **Customer app.**
  - `EditPriceScreen(listingId)` replaces the prototype: the real listing, one field, the live "You would receive" from `/reference/quote`, *Save*.
  - The piece page shows *Change fee* / *Change price* when `canChangePrice` is true, and the reserved-piece explanation.
  - `R.editprice` leaves `mockScreens` and the `MockMark` goes.
  - New keys `listing.price.*` in `lib/core/text/keys/listing_price.dart`. English is the code's own; **every Arabic default is AR-DRAFT** for the owner's review.

## 3. Phases and order

Phase 1 setup → 2 foundational → 3 (US1 Backend) → 4 (US2 Backend + Dashboard) → 5 (US1 Customer app) → 6 docs and verify.

- Inside each phase: Backend → contract (`composer swagger:generate`) → Dashboard → Customer app.
- **T006 matters.** The new guard refuses a price write once a buyer is in line, so run the existing order / buy-request / invoice / free-relist / market-maker / listing suites early (targeted). Fix any factory or test that writes a price after the state moved by setting the price first. Never weaken the guard.
- **Work through all six phases without stopping for approval between them.** Run each phase's checkpoint yourself (targeted tests; Dashboard type-check/lint/build; Flutter analyze/test/build) and carry on. Pause only for a real blocker:
  - a missing database;
  - a spec/code disagreement;
  - a failure you cannot fix within the plan.

  Report once at the end in the Step 5 shape.

## 4. Rules that still apply

- **Run the full test suite only once, at the end, in the background** (T040): `DB_DATABASE=dahab_wt022 DB_USERNAME=… DB_PASSWORD=… composer test > storage/logs/pest-022.log 2>&1`, sequential. Run targeted files while building. Afterwards rerun only the failing files, and tell the owner.
- **Flutter checks** (T031, T038): `flutter analyze`, `flutter test`, `flutter build web --release`. **Dashboard checks** (T023, T038): `npm run type-check`, `npm run lint -- <touched files>` (older files have lint errors), `npm run build`.
- **App texts** (T029):
  1. If literals moved, run `python tool/gen_text_catalogue.py`.
  2. Run `UPDATE_TEXT_MANIFEST=1 flutter test test/text_manifest_test.dart`.
  3. Copy `assets/text/keys.json` to the Backend `database/data/app_text_keys.json`.
  4. Run `php artisan app-texts:sync` against `dahab_wt022_dev` only.

  Keys are append-only (never rename one). No new customer-visible text without a key.
- **The Backend is the source of truth; never invent API behaviour.** If the spec and the code disagree, **stop and report**; never choose silently. When a frontend needs something the API lacks, state it as a Backend requirement for the owner to approve.
- **In the same task as each endpoint**, update:
  - `#[OA]` and `composer swagger:generate`;
  - the Postman request (*Customer → Listings → Change price of a live piece*);
  - Pest;
  - the inventory tests: `OpenApiGenerationTest`, the `PrincipalIsolationTest` route inventory, `AuditCatalogueTest`.

  At the end, update:
  - `docs/platform/api-contract.md`;
  - Technical Spec Part 2 §3 ("Changed by spec 022");
  - `docs/features/listings.md`;
  - `docs/features/live-listing-price-change.md`;
  - the `CLAUDE.md` current-state section.
- **Python edit scripts** open files with `encoding='utf-8'` and `newline=''`. Dashboard and Flutter files mix CRLF and LF per file: detect each file's endings and keep them. Write helper scripts with the Write tool, not shell heredocs with apostrophes.
- **Git**:
  - no `Co-Authored-By`, no `Claude-Session`, no "Generated with" text, and no AI or model name in any commit, merge or PR;
  - plain messages in the project's style (`feat(listings): …`, `docs(listings): …`, `test(listings): …`);
  - never change `user.name` or `user.email`;
  - each repo gets its own commits;
  - commit only when the owner asks, and never push without being asked.

## 5. Known open items: do not touch unless asked

- **Spec 023** (first-sale advance) must extend `listing_price_locked()` so a piece with an advance paid cannot change price. Do not build any of 023 here.
- **Spec 024** may add a "price dropped" alert for savers. D22-2 says no for now.
- **Spec 019–021 leftovers**: Finance sign-off of the market-maker spread (D3), the AR-DRAFT review of app and notification texts (the new `listing.price.*` Arabic joins that review), Terms §5.5 with the lawyer.
- **Pre-existing noise**:
  - `InvoicesPerformanceTest` is timing-sensitive on a loaded machine (it passes alone);
  - Pint wants to reformat `StartCustomerEmailVerificationAction` and `StartCustomerRegistrationAction` (not ours);
  - Dashboard `npm run lint` has errors in older files.
- **The mock order screens' "Change fee" actions** in `lib/mock/mock_orders.dart` stay; they belong to the mock repositories.
