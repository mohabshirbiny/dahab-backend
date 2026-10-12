# Implementation Plan: First-sale advance, customer-side codes, referral, and the Promo codes / Market maker approvals pages (spec 023)

**Branch**: `feature/first-sale-and-referral` in Backend, Dashboard and Customer app (created from each `main` **only when the owner asks**; this planning session ran in a cloud container on the Backend branch it was given, with read-only shallow clones of the other two repos) | **Date**: 2026-10-12 | **Spec**: [spec.md](spec.md)

**Status**: Plan only. Nothing is implemented, committed or pushed. The owner's answers (2026-10-12) in `spec.md` are final and are not reopened; where they leave a gap, [research.md](research.md) R31 lists the recommendation (**P23-1…11**).

**Bases verified**: Backend `main` f2e308b (spec 022 + the owner's answers), Dashboard `main` 6d0a82c, Customer app `main` a993878.

## Summary

Six stories, one money rule. **Codes and credits** (Stories 3–5): the Dashboard form of the design becomes real. A code can be tied to one account or open to anyone, can waive the commission, reduce it by a percentage or leave it, can give the spread to a dealer, and carries a use limit, a monthly cap, dates and (for dealers) an age and an approval. Seller-side codes and the referral reward are **commission credits** — one table pair, not wallet money, spent only by lowering the commission of a later settlement. At settlement the commission is the **lowest** of: ordinary, free relist, market-maker waiver, and one credit; never a sum; the minimum commission floors every reduction in code **and** in a table CHECK. **Advance** (Story 1): a first gold seller is paid ahead at the inspection that passes (`external_equity −A / seller +A`), reconciled at the buyer's payment (`external_equity +min(A, proceeds)`), written off when the order ends unpaid; all behind a switch that is **off**, with an exposure ceiling and a write-off rule as settings. **Sell form and referral** (Stories 2, 4): the card talks to the Backend; *Invite a friend* becomes real. **Market maker approvals** (Story 6): views, *Against the guide*, files, *Leave* and a cost panel made of figures **stored on the order** at settlement.

## What each person sees

1. **Seller, sell form.** Types `WELCOME`; the card says "50% off the commission. The 200 EGP minimum commission still applies.", the estimate shows *Promo saving*, and — if the code pays the first gold sale ahead — "Paid as soon as IGI confirms your piece, up to 100,000." The code is recorded with the listing.
2. **Seller, order.** After IGI passes the piece, *Advance paid* and a push/SMS; after the buyer pays, *Paid earlier* + the remainder, and *You saved 300 on the commission*.
3. **Friend / inviter.** The friend types the inviter's code in the same card. The inviter's *Invite a friend* screen shows the code, *Invites sent / Joined / Sold something / Earned so far*, and the 200 EGP credit lands only after the friend's first sale.
4. **Finance.** *Commission rates* has the switches, the ceiling and the exposure panel; the Customer file has *Enable the first-sale advance*; *Promo codes* is the designed table and *New code* form; *Market maker approvals* is the designed queue with views, files and *Against the guide*, and *What this costs* in real money.

## Impact analysis

```
Backend:          YES — resolver CommissionWaivers + settlement/advance/write-off/referral Actions, redemption + preview Actions,
                  Create/UpdatePromoCode rewritten, PromoCodeGate generalised, queue/cost/leave, views job, staff + customer
                  controllers with #[OA], notifications (3 templates), 2 sweeps + 1 prune command, 1 limiter, settings catalogue,
                  1 permission, ~20 audit events, DH024–DH027 mapping, Postman
Database:         YES — 4 migrations: promo_code terms + use log + ID hash + 16 settings; commission_credit(+event), listing_promo,
                  referral_code, referral, "order" cost columns, promo scope, functions; first_sale_enablement/advance + the 022
                  function replaced; listing_view
API:              YES — 11 new endpoints, ~10 additive changes, 0 breaking (contracts/*)
Dashboard:        YES — Promo codes and Market maker pages rewritten to the design; Commission rates rows + exposure panel; Customer
                  file action; identity-review ID number; staff order detail blocks
Customer App:     YES — shared PromoCard (sell1 + sell3), SellDraft.promoCode, referral repository + Invite screen real, order screen
                  (advance, saved commission), texts promo.* / referral.* (AR-DRAFT), mock removal, fake backend + tests
Auth:             NO — existing tokens and gates (a new non-elevated database scope `promo`, not an auth change)
Permissions:      YES — one new code: first_sale.manage; existing promo.manage / market_maker.approve / pricing.rates.manage reused
API models/types: YES — Dashboard PromoCodeRow/QueueRow/CostPanel/FirstSaleAdvance; Flutter PromoPreview, Referral, CommissionCredit,
                  Listing.promo/paysAdvance, Order.advance/commission
```

**Consumers checked.** Dashboard: `src/composables/useMarketMakers.ts` (`PromoCodeRow`, `QueueRow`, `CostPanel`) maps explicitly — unknown fields are ignored, so every additive field is safe before the Dashboard ships; the current pages are rewritten here. `src/pages/rates/index.vue` lists settings from a static array (new keys must be added there; the API already returns them). Flutter: `Listing.fromJson`/`Order.fromJson` ignore unknown keys; `PromoCode` (model), `ContentRepository.promo()`, `mockPromoCodes`, `Pricing.promoSaving` are the only promo code paths and call no API; `InviteScreen` is a mock in `mockScreens`. The Postman collection has one folder for promo codes and one for market makers.

## Technical Context

- **Language/Version**: PHP 8.4 locally (composer platform 8.3+), Laravel 12; Vue 3 + TypeScript (Vuetify 4); Flutter (Dart ^3.11).
- **Primary dependencies reused**: `PostLedgerEntryAction`, `OrderSettlement`, `PriceCalculator`, `ListingPricer`, `Settings`/`SettingKey`, `MovesOrder`/`MovesListing`, `RecordAuditLogAction`, `TellsOrderParties`, the template catalogue, `DatabaseActor` scopes, `idempotent`, `PromoCodeGate`, `listing_price_locked()`, `SettingPlaceholders`, `TextKey`.
- **Storage**: PostgreSQL 16; tests on **`dahab_wt023`**, dev data on **`dahab_wt023_dev`**, both created by the owner; never `dahab`.
- **Testing**: Pest at the HTTP boundary + ledger sums + migration tests; Dashboard type-check / lint (touched files) / build; Flutter analyze / test / build web.
- **Constraints**: no business number in code (16 settings); no new ledger account or event kind; no RLS widened beyond the `promo` scope's list; the sell estimate and the settlement use one resolver; nothing is posted or switched on by deploying.
- **Scale/Scope**: 4 migrations, 10 new tables, 11 endpoints, ~12 Actions, ~80 Pest cases, 2 Dashboard pages rewritten + 4 touched, ~10 Flutter files.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I Named actor | Every credit event, enablement, advance, referral move and audit row names a customer, a staff member or the system staff (`num_nonnulls = 1`); the advance entry's actor is the system staff or the enabling staff. | PASS |
| II Isolation / permission data | New non-elevated `promo` scope with an explicit policy list; cross-customer facts via integer-only functions; `first_sale.manage` is permission data seeded by `seedRoles()`; the `order` scope policies are limited to a seller of the caller's own order. | PASS (gated by T008, T014, T016) |
| III Docs are the source of truth | Technical Spec Part 2 §6/§7, Part 3 §2.5/§3/§6/§8/§10 get "Changed by spec 023" notes; `04/05_schema*.sql`, `00_schema_full.sql`, `api-contract.md`, `docs/features/*`, `CLAUDE.md`, the feature doc. | PASS (gated by tasks) |
| IV Foundation before modules | Builds on 005, 008, 010–012, 016, 018, 019, 022. Action → FormRequest → Resource → `#[OA]` → Pest per endpoint. | PASS |
| V Test the boundary and the ledger | Every settlement case asserts the ledger sums to zero and the invoice reconciles; the minimum is tested at the HTTP boundary and by a direct `UPDATE`; concurrency tests for the cap, the ceiling and the credit. | PASS |

Post-design re-check: **PASS**. Principle II is the one to watch (T016).

## Project Structure

### Documentation (this feature)

```text
specs/023-first-sale-and-referral/
├── spec.md                         # + the owner's answers (2026-10-12)
├── plan.md                         # this file
├── research.md                     # R1–R32, open planning decisions P23-1…11
├── data-model.md                   # 4 migrations, settings (§7), texts (§8)
├── contracts/{staff-promo-api,customer-sell-api,referral-api,codes-and-events}.md
├── quickstart.md
├── tasks.md
└── implement-session-prompt.md
docs/features/first-sale-and-referral.md
```

### Source code (planned)

```text
Backend  database/migrations/2026_10_16_0000{10,20,30,40}_*.php (+ docs/Database schema mirrors)
         app/Support/Pricing/{CommissionWaivers (new), CommissionResolution, PricingRates (min floor)}
         app/Support/Orders/{OrderSettlement, SettlementFigures}      (resolver, advance reconciliation, cost columns)
         app/Support/MarketMakers/PromoCodeGate.php                    (terms, effective age/approval)
         app/Support/Promo/{SellerCodeGate, CustomerIdKey, PromoLabels}
         app/Actions/Promo/{ApplyListingPromoAction, RemoveListingPromoAction, PreviewCommissionAction, SpendCommissionCreditAction,
                            ExpireCommissionCreditsAction}
         app/Actions/MarketMakers/{CreatePromoCodeAction, UpdatePromoCodeAction, ListMarketMakerQueueAction, LeaveMarketMakerQueueAction,
                                   ReadQueueMediaAction, MarketMakerCostAction}
         app/Actions/FirstSale/{AdvanceFirstSaleAction, WriteOffFirstSaleAdvanceAction, EnableFirstSaleAction, RevokeFirstSaleAction,
                                ListFirstSaleAdvancesAction}
         app/Actions/Referral/{ShowReferralAction, CountShareAction, JoinReferralAction, GrantReferralRewardAction}
         app/Actions/Orders/{Customer/PayBalanceAction, Customer/DecideAdjustmentAction, ForfeitDepositAction, Concerns/MovesOrder}  (hooks)
         app/Actions/Inspections/RecordInspectionResultAction.php      (advance hook)
         app/Actions/Identity/ReviewIdentityDocumentAction.php         (id_number_hash)
         app/Jobs/{RecordListingViewJob, GrantReferralRewardJob}; app/Console/Commands/{ExpireCommissionCredits, PruneListingViews, RewardPendingReferrals}
         app/Http/Controllers/Api/V1/{Dashboard/MarketMakerController, Dashboard/FirstSaleController, Customer/PromoController,
                                      Customer/ReferralController, Customer/ListingController, Market/MarketListingController}
         app/Http/Requests/{Dashboard/Promo/*, Customer/Promo/*, Customer/Listing/StoreListingRequest, Identity/ReviewIdentityDocumentRequest}
         app/Http/Resources/{Customer/ListingResource, Customer/CustomerOrderResource, Staff/*}
         app/Models/* (§5 of data-model); app/Enums/{SettingKey, StaffPermission, OrderEvent, AuditEvent, + new enums}
         app/Notifications/Templates/CatalogueData.php; app/Support/Content/SettingPlaceholders.php; app/Exceptions/DomainApiException.php
         app/Support/DatabaseActor.php (scope promo); app/Providers/AppServiceProvider.php (limiter); config/dahab-promo.php
         bootstrap/app.php (DH024–DH027); routes/api.php, routes/console.php; postman/Dahab-Backend.postman_collection.json
         tests/Feature/{Promo,FirstSale,Referral,MarketMakers,Migrations,Settlement}/…, tests/Unit/{Pricing,Audit}/…
Dashboard src/pages/{promos,market-maker,rates,customer}/…, src/components/{promo,market-maker,first-sale}/…,
         src/composables/{useMarketMakers,usePromoCodes,useFirstSale}.ts, src/services/{customer,order,identity-document}.service.ts,
         src/types/{promo,marketMaker,firstSale,order,customer,identity}.ts, src/api/endpoints.ts, src/mock/nav.ts
App      lib/models/{promo,referral,listing,order}.dart, lib/services/{repositories,sell_draft,mock_repositories}.dart, lib/services/api/{promo_api,referral_api,listings_api,orders_api}.dart,
         lib/features/{sell/sell1_screen,sell/sell3_screen,sell/promo_card,account/invite_screen,orders/*}.dart,
         lib/core/text/keys/{promo,referral,all_keys}.dart, assets/text/keys.json, lib/routing/*, lib/widgets/mock_flag.dart,
         lib/mock/mock_account.dart (mockPromoCodes out), test/{test_app,flows_test,mock_flags_test,promo_test}.dart
```

## Phases and order

| Phase | Content | Depends on |
|---|---|---|
| **1 Setup** | databases checked, branches when asked, `vendor/`, feature doc | — |
| **2 Foundational** | settings catalogue; M1–M4 + schema mirrors + migration tests; models, enums, factories; scope `promo`; codes, audit events, permission, DH mapping; resolver (pure, unit-tested) | 1 |
| **3 US5 Promo codes (Backend)** | gate generalised; Create/Update/list/uses rewritten; ID number at review; Postman | 2 |
| **4 US3 + US2 Percent codes and the sell form (Backend)** | settlement integration (cost columns, floor, DH026); redemption, preview, listing promo; sweeps; Pest incl. ledger + invoice reconciliation | 3 |
| **5 US1 Advance (Backend)** | advance, reconciliation, write-off, enablement, exposure, notifications, 022 coupling | 4 |
| **6 US4 Referral (Backend)** | codes, join, reward job + healer, endpoints, notifications | 4 |
| **7 US6 Market maker approvals (Backend)** | views job, guide, files, leave, cost | 4 |
| **8 Dashboard** | the two pages, rates rows + exposure, Customer file, identity form, order blocks | 3–7 |
| **9 Customer app** | PromoCard, draft, referral, orders, texts, mock removal, tests | 4–6 |
| **10 Docs and verify** | docs, `CLAUDE.md`, one full Pest run, Dashboard + Flutter checks, `/speckit-analyze`, Step 5 report | all |

Inside each Backend phase: migration → model/factory → Action → FormRequest/Resource/controller + `#[OA]` → Pest → Postman → `swagger:generate`; then the Dashboard, then the Customer app (CLAUDE.md Part 1, Step 3). Phases 5, 6, 7 are independent after 4 and may be built in parallel in separate worktrees; 8 and 9 start per story as its Backend phase is green.

## Risks and watch-points

- **Money moves on deploy? No.** Every money-moving path (advance) and every customer-visible code path is behind a setting that defaults **off**; a test proves a fresh database posts no `first_sale_payout` and refuses seller codes and referrals (`not_open`). The market-maker path (live since spec 019) is not behind a switch, as before.
- **The settlement is the most-used money path.** The resolver replaces the boolean waiver. T041 and T091 re-run the whole settlement, invoice, free-relist and market-maker suites; the golden worked examples of TS §3.3 and §3.4 must still reproduce to the piastre with no waiver.
- **Existing tests that assume the old waiver or `promo_kind_not_available`.** `MarketMakerTest` (`kind => first_sale` refused), `MarketMakerSettlementTest`, spec 018 free-relist settlement. They change meaning on purpose; fix the tests, not the rules.
- **RLS.** The settlement runs as the buyer in the `order` scope but spends the **seller's** credit; the policy must be limited to the seller of an order the caller is a party to. T016 tests that a buyer cannot read or spend any other customer's credit, and that the `promo` scope cannot read `commission_credit` of others.
- **Reward ordering.** The inviter's credit is granted after commit by a job; a customer who sells again immediately may settle before the credit exists. Acceptable (the reward is not owed to the sale in flight); the healer covers lost jobs.
- **ID capture is a prerequisite for the guarantee (P23-1).** Until reviewers key the number, "once per ID" is per account. The report says so.
- **Advance and corrections.** A corrected inspection that turns a passed result into a cancel after an advance writes the advance off (the hook sits in `moveOrder`). T052 covers a karat correction after an advance.
- **Cost of the cap check.** The cap sums uses by code and month; indexed `(code, used_at)`; bounded by the code's monthly volume.
- **Views job when Redis is down** loses a count, never a page (dispatch after response).
- **Text manifest round trip.** New `promo.*`/`referral.*` keys must reach the Backend registry in the same change (T085); until then the app falls back to its defaults, so nothing breaks.
- **Spec 024.** Exposure vs ceiling and cap proximity are alert sources; this spec only audits and exposes the numbers.
- **Line endings.** Backend LF. Dashboard/Flutter files mix CRLF and LF per file; detect at edit time.
