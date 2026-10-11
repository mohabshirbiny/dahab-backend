# Implementation Plan: Change the price of a live piece

**Branch**: `feature/live-listing-price-change` in Backend, Dashboard and Customer app (created from each `main` **only when the owner asks**; this planning session ran in a cloud container on the Backend branch it was given) | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Status**: Plan only. Nothing is implemented, committed or pushed. D22-1…3 and C1–C3 are the owner's (2026-10-11).

**Bases verified**: Backend `main` 598944f (spec 021 merged), Dashboard `main` 613a753 (spec 021 merged), Customer app `main` 2417eab (read-only clones in the planning container).

## Summary

A seller changes the one figure they own on a **live** piece — the making charge per gram (gold) or the asking price (diamond, gold with diamond) — through a new `POST /customer/me/listings/{listing}/price` (C1). The change is live at once, with no review (D22-1), nobody is notified (D22-2), and there is no cooldown or cap (D22-3). It is refused once a buyer is in line (`listing_has_requests`), for any other non-live state (`listing_not_live`), under "stop everything" (`platform_stopped`, C2) and while the piece is approved for market makers (`listing_price_locked`, C3). Every change is a row in a new append-only `listing_price_change` and an audit entry; staff see the history on the listing detail. The database gains the backstop that was missing: the price columns can no longer move once a buyer is in line, because settlement reads them at payment (DH023).

## What the seller sees

1. On their live piece: *Change fee* (gold) or *Change price* (stones), shown when `can_change_price` is true.
2. The form: "Gold bracelet, 18K, 5.20 g" from the real listing, the current figure, one field, and "You would receive" refreshed from `GET /reference/quote` as they type.
3. *Save* → the piece page shows the new price. If a buyer joined meanwhile: "Someone has already asked to buy this piece, so its price can't change." If the piece is market-maker approved: "This piece's price is fixed for now. Contact us if you need to change it."

## Impact analysis

```
Backend:          YES — ChangeListingPriceAction; ChangeListingPriceRequest; CustomerListingController@price + #[OA];
                  ListingPriceChange model + factory, ListingPriceField enum; Listing::priceChanges(); CustomerListing can_change_price;
                  staff ListingResource price_history; AuditEvent listing.price_changed; 3 new error codes + listing_not_live reuse;
                  DH023 mapping in bootstrap/app.php; route; Postman
Database:         YES — 1 migration: table listing_price_change (RLS, append-only), function listing_price_locked(), triggers
                  trg_listing_price_guard + trg_listing_price_recorded (DH023); no setting, no permission, no seed
API:              YES — 1 new endpoint; 2 additive fields (can_change_price, price_history); non-breaking (contracts §6)
Dashboard:        YES — read only: types/listing.ts, services/listing.service.ts, new components/listings/ListingPriceHistory.vue in
                  ListingReviewPanel.vue
Customer App:     YES — models/listing.dart (canChangePrice), services/repositories.dart + api/listings_api.dart (changePrice),
                  features/orders/order_flows.dart EditPriceScreen (real form, ?id=), routing/app_router.dart, features/catalog/
                  detail_screen.dart (button, MockMark removed), widgets/mock_flag.dart (R.editprice out), new text keys
                  listing.price.* (AR-DRAFT), test fake backend + tests; text manifest copied to the Backend
Auth:             NO — the existing customer token and trade gate
Permissions:      NO — staff read with the existing listing.* permissions; no new permission
API models/types: YES — Dashboard ListingDetail.priceHistory; Flutter Listing.canChangePrice
```

**Consumers checked**: Dashboard `src/services/listing.service.ts` maps the detail explicitly (unknown fields are ignored, so `price_history` is safe before the Dashboard ships). Flutter `Listing.fromJson` ignores unknown keys; `EditPriceScreen` is a mock with no API call today; the fake backend in `test/test_app.dart` answers `/customer/me/listings*`.

## Technical Context

- **Language/Version**: PHP 8.4 locally (composer platform 8.3+), Laravel 12; Vue 3 + TypeScript; Flutter (Dart ^3.11).
- **Primary Dependencies**: reused — `MovesListing::lockListing`, `ListingRules::MONEY`, `ListingPricer`, `Controls::assertPlatformRunning`, `RecordAuditLogAction`, the `idempotent` middleware, the `customer.listings` limiter, the `free_relist_origin_ok()` scope-switch pattern; Flutter `LiveRates.quote` / `QuoteParams`, `newIdempotencyKey()`, `TextKey`.
- **Storage**: PostgreSQL 16; tests on **`dahab_wt022`**, dev data on **`dahab_wt022_dev`**, both created by the owner; never `dahab`.
- **Testing**: Pest at the HTTP boundary; Dashboard type-check / lint (touched files) / build; Flutter analyze / test / build web.
- **Constraints**: no RLS widened; settlement must never see a figure that moved after a buyer joined; no customer text without a key; the seller is not told about market makers.
- **Scale/Scope**: 1 endpoint, 2 additive fields, 1 table, 1 function, 3 triggers, about 30 Pest cases, 1 Dashboard component, 1 Flutter screen rebuilt.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I Named actor | Each change names the seller in `listing_price_change` (`num_nonnulls = 1`) and in the audit row. | PASS |
| II Isolation / permission data | The Action runs in the customer scope; the one cross-table read (approval) goes through a boolean function, no policy widened. Staff read with existing permissions. | PASS |
| III Docs are the source of truth | Technical Spec Part 2 §3, `04_schema_market.sql`, `00_schema_full.sql`, `api-contract.md`, `docs/features/listings.md`, `CLAUDE.md`, the feature doc (R13). | PASS (gated by tasks) |
| IV Foundation before modules | Builds on specs 010, 011, 015, 019. Action, FormRequest, Resource, `#[OA]`, happy + refusal tests. | PASS |
| V Test the boundary | Every refusal and the race through HTTP; DH023 by direct `UPDATE`s; no money moves. | PASS |

Post-design re-check: **PASS**.

## Project Structure

### Documentation (this feature)

```text
specs/022-live-listing-price-change/
├── spec.md            # + D22-1…3, C1–C3
├── plan.md            # this file
├── research.md        # R1–R14
├── data-model.md
├── contracts/listing-price-api.md
├── quickstart.md
├── tasks.md
└── implement-session-prompt.md
```

### Source code (planned)

```text
Backend  database/migrations/2026_10_15_000010_listing_price_change.php
         app/Actions/Listings/ChangeListingPriceAction.php
         app/Http/Requests/Customer/Listing/ChangeListingPriceRequest.php
         app/Http/Controllers/Api/V1/Customer/ListingController.php (price + #[OA])
         app/Http/Resources/Customer/ListingResource.php (can_change_price), Staff/ListingResource.php (priceHistory())
         app/Http/Controllers/Api/V1/Dashboard/ListingController.php (detail adds price_history)
         app/Actions/Listings/ListOwnListingsAction.php (price_locked column)
         app/Models/{ListingPriceChange (new), Listing}; database/factories/ListingPriceChangeFactory.php
         app/Enums/{ListingPriceField (new), AuditEvent}; app/Exceptions/DomainApiException.php; bootstrap/app.php (DH023)
         routes/api.php; postman/Dahab-Backend.postman_collection.json
         tests/Feature/Listing/{ChangeListingPriceTest, ListingPriceConcurrencyTest}.php,
         tests/Feature/Migrations/ListingPriceChangeMigrationTest.php, + the inventory/catalogue tests
Dashboard src/types/listing.ts, src/services/listing.service.ts,
         src/components/listings/{ListingPriceHistory.vue (new), ListingReviewPanel.vue}
App      lib/models/listing.dart, lib/services/repositories.dart, lib/services/api/listings_api.dart,
         lib/features/orders/order_flows.dart (EditPriceScreen), lib/routing/app_router.dart,
         lib/features/catalog/detail_screen.dart, lib/widgets/mock_flag.dart,
         lib/core/text/keys/{listing_price.dart (new), all_keys.dart}, assets/text/keys.json,
         test/{test_app.dart, flows_test.dart, mock_flags_test.dart}
```

## Phases and order

| Phase | Content | Depends on |
|---|---|---|
| **1 Setup** | databases checked, branches when asked, `vendor/`, feature doc | — |
| **2 Foundational** | migration + schema mirror + migration test; model, enum, factory; codes, audit event, DH023 map + catalogue tests | 1 |
| **3 US1 Backend** | request → Action → controller/route/`#[OA]` → `can_change_price` → Pest (refusals, race) → Postman → `swagger:generate` | 2 |
| **4 US2 Backend + Dashboard** | `price_history` on the staff detail + Pest → Dashboard types/service/component | 2 (3 for data) |
| **5 US1 Customer app** | model, repository, screen, piece page, mock flag out, keys (AR-DRAFT), manifest → Backend sync, tests | 3 |
| **6 Docs and verify** | docs, `CLAUDE.md`, one full Pest run (background), Dashboard and Flutter checks, Step 5 report | all |

## Risks and watch-points

- **Settlement reads the listing's figure.** The new guard makes that safe; but any existing test that writes a price on a reserved/accepted listing directly (factories in order/settlement tests) will now fail with DH023. T006 runs the order, buy-request, invoice and free-relist suites early to find them; the fix is to set the price before moving the state, never to weaken the guard.
- **Buyer saw the old price.** Covered by the existing `price_moved` tolerance check (spec 011); a test proves it.
- **Approval lock leaks nothing.** The seller learns only that the price is "fixed for now". If support is asked, staff see the approval in the Market maker page.
- **Shared limiter.** `customer.listings` (20 / min) also counts new listings; enough for a person, and D22-3 asked for no other limit.
- **Text manifest round trip.** New keys must reach the Backend registry (`database/data/app_text_keys.json` + `app-texts:sync`) in the same change, or `GET /reference/app-texts` lacks them (the app falls back to its defaults, so nothing breaks).
- **Spec 023.** The advance must extend `listing_price_locked()`; noted in spec.md and the feature doc.
- **Line endings.** Dashboard and Flutter files mix CRLF and LF per file; the clones here showed LF, the owner's Windows checkout may differ — detect per file at edit time.
