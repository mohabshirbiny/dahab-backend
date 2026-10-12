# Prompt for the local session: implement spec 023 (First-sale advance, customer-side codes, referral, Promo codes and Market maker approvals)

Implement spec 023 on the owner's machine. The plan is finished and approved; this session builds it. Work through `tasks.md` in order, all ten phases, to the end.

## 0. State you start from

- Planning is committed and pushed on the Backend branch **`claude/wizardly-mendel-jezw5y`** (Backend `main` f2e308b + one docs commit; `main` does not have the spec 023 files yet). `main` already has spec 022 merged and the owner's answers recorded in `spec.md`. The files are in `specs/023-first-sale-and-referral/` and `docs/features/first-sale-and-referral.md`.
- These are **final** (the owner, 2026-10-12); do not reopen them:
  - D23-1…9 as answered in `spec.md` (open-to-anyone or one account; percentage codes and referral **built**; no price change after an advance; views counted; *Leave* changes nothing; *Once per ID* is per national ID);
  - the two rules: the design wins where nothing else is written; every figure is a **setting** with the design's value as default — **no business number in code**.
- `research.md` R31 lists the planning decisions the owner has **not** answered (**P23-1…11**). Build the recommendation in each, and list all of them in the final report. If you meet a rule that is neither written nor derivable, **stop and report**; never choose silently.
- Create the branch `feature/first-sale-and-referral` in **all three repos**:
  - Backend (`D:\laragon\www\dahab-backend`): `git fetch origin && git switch -c feature/first-sale-and-referral origin/claude/wizardly-mendel-jezw5y`;
  - Dashboard (`..\dahab-dashboard`): from `main` (6d0a82c or later);
  - Customer app (`..\dahab-flutter`): from `main` (a993878 or later).
  - In a Backend worktree, run `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` until it prints "Nothing to install", and confirm `vendor/mpdf/mpdf/src/Mpdf.php` exists. **Never copy `vendor/` from another checkout.** Copy the main `.env`.
- **Databases first (T001).**
  - The owner creates `dahab_wt023` (suite) and `dahab_wt023_dev` (dev data); the `dahab` user has no CREATEDB. Check with `psql -l`. If either is missing, STOP and ask.
  - **Never touch the primary `dahab` database**: no tests, no `migrate:fresh`, no seeders, no `app-texts:sync` there.
  - Pass `DB_DATABASE/DB_USERNAME/DB_PASSWORD` on the command line every time.
- Use PHP 8.4.
- Servers: the owner starts the API (`127.0.0.1:8000`), the Dashboard (`localhost:3000`) and the app. Do not leave any running.

## 1. Read first

1. `CLAUDE.md` (Part 1 platform rules, Part 2 Backend rules) and `docs/platform/*.md`.
2. The spec 023 files:
   - `tasks.md` is the work list (Backend tasks unmarked; **[D]** Dashboard; **[A]** Customer app);
   - `research.md` R1–R32 holds every design decision and the open P23 list;
   - `contracts/*.md` has the exact API, codes, SQLSTATE, audit events, permissions, throttles;
   - `data-model.md` has the four migrations, CHECKs, guards DH024–DH027, the 16 settings (§7) and the app-text keys (§8);
   - `quickstart.md` is how to verify by hand.
3. The Technical Spec: Part 3 §2.5 (commission, minimum, waivers), §3 (settlement, escrow pass-through), §6 (the advance, in full), §8 (market makers), §10.1 (no-pay), OI-3.3; Part 2 §6–§7. The Dashboard design pages `p-promos`, `p-mm`, `p-rates` in `docs/dahab-admin-dashboard.html` and the app prototype in `docs/dahab-app-prototype.html` (promo card, *Invite a friend*).
4. The built code the plan builds on:
   - Backend:
     - spec 019: `PromoCode`, `PromoCodeUse`, `PromoCodeGate`, `CreatePromoCodeAction`, `UpdatePromoCodeAction`, `MarketMakerController`, `ListMarketMakerQueueAction`, `ApproveForMarketMakersAction`, `SendBuyRequestAction` (`promo_code`), the migrations `2026_10_12_000040/45`;
     - settlement: `OrderSettlement` (`compute`, `post`), `SettlementFigures`, `PriceCalculator::lockedBreakdown/finish`, `Piece`, `PricingRates`, `PayBalanceAction`, `RecordInspectionResultAction`, `DecideAdjustmentAction`, `ForfeitDepositAction`, `MovesOrder`, `MovesListing`, `OrderTransitions`;
     - money: `PostLedgerEntryAction`, `LedgerEntry`/`LedgerLine`, `AccountKind`, `LedgerEventKind`, `DepositLedger`;
     - invoices: `IssueTaxInvoices`, `InvoiceLines`, the DH012 trigger in `2026_10_09_000010_create_tax_invoices.php`;
     - spec 018: `Listing::isFreeRelist()`; spec 022: `listing_price_locked()` in `2026_10_15_000010_listing_price_change.php`, `ListingPriceLocks`, `ChangeListingPriceAction`;
     - settings and content: `SettingKey`, `Settings`, `SettingController`, `SettingPlaceholders`, the template catalogue (`CatalogueData`, `tests/Fixtures/notification-golden.json`), `app-texts:sync`;
     - scopes and RLS: `DatabaseActor`, the `queue`/`order`/`market` policies, `free_relist_origin_ok()`, `listing_price_locked()` (the scope-switch pattern);
     - `ReviewIdentityDocumentAction`, `StaffPermission`, `AuditEvent`, `RecordAuditLogAction`, `DomainApiException`, `bootstrap/app.php` (SQLSTATE maps), `routes/api.php`, `routes/console.php`.
   - Dashboard: `src/pages/{promos,market-maker,rates}/index.vue`, `src/composables/useMarketMakers.ts`, `src/types/staff.ts`, `src/api/endpoints.ts`, `src/services/{customer,order,identity-document}.service.ts`, `src/mock/nav.ts`, the identity review components.
   - Customer app: `lib/features/sell/{sell1_screen,sell3_screen}.dart`, `lib/services/{sell_draft,repositories,mock_repositories,pricing,live_rates}.dart`, `lib/services/api/*`, `lib/models/{account,listing,order}.dart`, `lib/mock/mock_account.dart`, `lib/features/account/invite_screen.dart`, `lib/features/orders/*`, `lib/widgets/mock_flag.dart`, `lib/core/text/keys/` (`listing_price.dart` and `orders_after_collection.dart` are the pattern for a hand-written keys file with AR-DRAFT), `tool/gen_text_catalogue.py`, `test/test_app.dart`, `test/flows_test.dart`, `test/mock_flags_test.dart`.
5. Skills: `laravel-migrations`, `laravel-eloquent-models`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-queues-notifications`, `laravel-pest-testing`, `laravel-quality-gates`.

## 2. What to build (summary; the files win)

- **One commission-credit mechanism** (`commission_credit` + append-only events; not wallet money; spent only by lowering a settlement's commission) shared by seller-side codes and the referral. **One resolver**, `CommissionWaivers`, used by the settlement, the advance and the preview:
  - candidates: none, free relist, market-maker waiver, one credit;
  - the **lowest** commission applies, never a sum; tie priority free relist > market maker > code credit > referral credit;
  - `percent` and `amount` credits are floored at `commission.minimum_egp`; `waive` and the existing waivers are not (TS §2.5);
  - a credit that cannot lower the commission is not spent;
  - the minimum is enforced in the settlement **and** by a table CHECK on `"order"`.
- **Cost figures stored on the order at settlement**: ordinary commission, commission given up, waiver kind and credit, floor, spread given up, `settled_at`. The *What this costs* panel sums stored values; earlier sales are "not recorded".
- **The advance**, behind `payout.first_sale_enabled` (**default off**): eligibility, the single balanced `first_sale_payout` entry (`external_equity −A`, seller available `+A`), exposure ceiling and the advisory lock, reconciliation at payment (`external_equity +min(A, proceeds)`, shortfall stored, never clawed back), write-off when the order ends unpaid (a status change, no posting; `moveOrder` hook), the offset setting, staff enablement, `listing_price_locked()` extended (any advance row).
- **Promo codes (staff)** to the design: the new terms on `promo_code` (immutable after creation), kind inference, `tied_to` by number or phone, the list shape, the uses log; backward compatible with the spec 019 body; the `id_number_hash` captured at identity review for *Once per ID*.
- **Customer side**: `POST /customer/me/commission-preview`, `PUT|DELETE /customer/me/listings/{l}/promo`, `promo_code` on listing creation, `GET /customer/me/referral`, `POST …/referral/shares`, `GET …/commission-credits`, additive listing/order fields. Seller-side codes and referral are behind `promo.seller_codes_enabled` and `referral.enabled` (**default off**).
- **Referral**: the friend's bound percent credit at redemption; the inviter's EGP credit granted **after the friend's settled sale** by a job after commit plus a healer command.
- **Market maker approvals**: the view counter (job after response, hashed, one per viewer per piece per day, staff only), *Against the guide* (gold making charge % of gold, tone from a setting; stones "No guide yet"), the file list with audited opening for the approver, **Leave** (audit only), the stored-figure cost panel.
- **Four migrations** (`2026_10_16_0000{10,20,30,40}`), mirrored in `docs/Database schema/`; `down()` refuses once data that is tied to the ledger or audit exists; `bootstrap/app.php` maps DH024–DH027.
- **Dashboard.** Promo codes and Market maker approvals rewritten to the design (field by field); *Commission rates* rows and the exposure panel; Customer file card; identity ID number; staff order blocks.
- **Customer app.** One `PromoCard` on the sell form, `SellDraft.promoCode`, conditional "paid early" text, order advance and saved commission, real *Invite a friend*, keys `promo.*`/`referral.*` (AR-DRAFT), mocks removed.

## 3. Phases and order

Phase 1 setup → 2 foundational (settings, migrations, scope, enums/models, codes, resolver) → 3 US5 Backend → 4 US3 + US2 Backend → then 5 (US1), 6 (US4), 7 (US6) Backend → 8 Dashboard → 9 Customer app → 10 docs and verify.

- Inside each phase: Backend → contract (`composer swagger:generate`) → Dashboard → Customer app.
- **T024 matters.** The settlement changes from a boolean to a resolver and `promo_kind_not_available` disappears. Run the existing market-maker, order, buy-request, invoice, free-relist, listing and settings suites early (targeted) and fix tests that assumed the old behaviour. Never weaken a rule to make an old test pass; the TS §3.3 and §3.4 worked examples must still reproduce to the piastre with no waiver.
- **Work through all ten phases without stopping for approval between them.** Run each phase's checkpoint yourself (targeted tests; Dashboard type-check/lint/build; Flutter analyze/test/build) and carry on. Pause only for a real blocker:
  - a missing database;
  - a spec/code disagreement or a rule that is neither written nor derivable;
  - a failure you cannot fix within the plan.

  Report once at the end in the Step 5 shape.

## 4. Rules that still apply

- **Run the full test suite only once, at the end, in the background** (T091): `DB_DATABASE=dahab_wt023 DB_USERNAME=… DB_PASSWORD=… composer test > storage/logs/pest-023.log 2>&1`, sequential. Run targeted files while building. Afterwards rerun only the failing files, and tell the owner.
- **Money tests.** Every test that moves money asserts the entry sums to zero; every settlement test asserts the invoice reconciles (DH012). A fresh database must post **no** `first_sale_payout` and refuse seller codes and referrals (`not_open`).
- **Never invent behaviour.** The Backend is the source of truth. When a frontend needs something the API lacks, state it as a Backend requirement for the owner to approve.
- **No business number in code.** Rates, caps, limits, days, percentages and amounts come from `Settings` or from the code row. A literal `200`, `0.5`, `100000` or `7` in new code is a bug (T090 greps for them).
- **Flutter checks** (T086): `flutter analyze`, `flutter test`, `flutter build web --release`. **Dashboard checks** (T077): `npm run type-check`, `npm run lint -- <touched files>` (older files have lint errors), `npm run build`.
- **App texts** (T085):
  1. If literals moved, run `python tool/gen_text_catalogue.py`.
  2. Run `UPDATE_TEXT_MANIFEST=1 flutter test test/text_manifest_test.dart`.
  3. Copy `assets/text/keys.json` to the Backend `database/data/app_text_keys.json`.
  4. Run `php artisan app-texts:sync` against `dahab_wt023_dev` only.

  Keys are append-only (never rename one). The Arabic defaults are AR-DRAFT for the owner's review.
- **In the same task as each endpoint**, update:
  - `#[OA]` and `composer swagger:generate`;
  - the Postman request (`postman/README.md`);
  - Pest;
  - the inventory tests: `OpenApiGenerationTest`, the `PrincipalIsolationTest` route inventory, `AuditCatalogueTest`, the permission catalogue test.

  At the end, update:
  - `docs/platform/api-contract.md`;
  - Technical Spec Part 2 §6–§7 and Part 3 §2.5, §3, §6, §8, §10.1 ("Changed by spec 023");
  - `docs/features/*` cross references and `docs/features/first-sale-and-referral.md`;
  - the `CLAUDE.md` current-state section.
- **Python edit scripts** open files with `encoding='utf-8'` and `newline=''`. Backend files are LF. Dashboard and Flutter files mix CRLF and LF per file: detect each file's endings and keep them. Write helper scripts with the Write tool, not shell heredocs with apostrophes.
- **Git**:
  - no `Co-Authored-By`, no `Claude-Session`, no "Generated with" text, and no AI or model name in any commit, merge or PR;
  - plain messages in the project's style (`feat(promo): …`, `feat(first-sale): …`, `docs(promo): …`, `test(first-sale): …`);
  - never change `user.name` or `user.email`;
  - each repo gets its own commits;
  - commit only when the owner asks, and never push without being asked.

## 5. Known open items: do not touch unless asked

- **Finance sign-off (D23-5)**: the advance's ledger, the exposure ceiling, the write-off rule, VAT on a reduced commission and the credit's accounting. The build does not wait for it; the three switches stay **off** in every seeded database. Do not turn them on anywhere but `dahab_wt023_dev` for your own checks.
- **Tax invoice wording (P23-7)**: the invoice shows the commission charged only. Do not add a discount line.
- **ID number for *Once per ID* (P23-1)**: older approvals have no hash and fall back to the account. Do not try to backfill.
- **Spec 024** will route alerts (exposure vs ceiling, cap proximity); this spec only audits and exposes the numbers. Do not build alerts.
- **Rapaport** (stone guide) and the **Tax Authority** integration stay out; stones show "No guide yet".
- **Spec 019–022 leftovers**: Finance sign-off of the market-maker spread (D3), the AR-DRAFT review of app and notification texts (the new `promo.*`/`referral.*` Arabic joins that review), Terms §5.5 with the lawyer.
- **Pre-existing noise**:
  - `InvoicesPerformanceTest` is timing-sensitive on a loaded machine (it passes alone);
  - Pint wants to reformat `StartCustomerEmailVerificationAction` and `StartCustomerRegistrationAction` (not ours);
  - Dashboard `npm run lint` has errors in older files.
- **The mock order screens' other actions** in `lib/mock/mock_orders.dart` stay; they belong to the mock repositories.
