# Implementation Plan: Listings — selling a piece and browsing the market

**Branch**: `feature/listings` (backend + dashboard; the Customer App has no repository). Spec work is on `claude/listings-spec-lifecycle-b5c3e6`. | **Date**: 2026-09-30 | **Spec**: [spec.md](./spec.md)

## Summary

The first half of the marketplace: a seller lists a piece, Dahab reviews it, anyone browses it.

- **Listing** — a verified, non-suspended customer uploads media (four new upload purposes on the existing encrypted private store), creates a draft with its branch options and the accepted ownership declaration, edits it while it is a draft or sent back, submits it, and withdraws it once live. They list and open only their own (forced RLS).
- **Review** — staff with the new `listing.*` codes see the queue (oldest first, counts for the chips), open a listing with all media and the seller, and **approve** (live, `listed_at`), **ask for changes** (message to the seller), **reject** (new final state) or **take down** a live one. Every decision is idempotent, audited and notified by SMS/email after commit.
- **Market** — a new public surface: list (filters, three sorts, keyset cursor), detail and public media, read under a read-only `market` database scope; `current_price` comes from the spec 005 calculator; no seller field exists in the response shapes and a test fails the build if one appears.
- **Guarantees in the data** — `listing_transition` + a guard trigger (SQLSTATE `DH004` → 409 `illegal_listing_transition`), a deferred trigger that requires a `listing_state_change` row for every move, row-shape CHECKs, forced RLS on every listing table.
- **Also** — public reference reads (karats, piece types, branches, the declaration text); `legal_document` + `agreement_acceptance`; suspending a customer holds their live listings and reinstating restores them.
- **Apps** — Dashboard *Listings to review*; Customer App sell flow, *My listings*, *Browse* and the piece page on the API.

## Impact analysis

```
Backend:          YES — 2 migrations, 4 enums (+4 extended), 7 models, ~18 Actions, Requests, Resources, 5 controllers, 1 notification, 3 permissions, 4 audit events + 1 category, 8 error codes, 1 middleware, 2 throttles, seeders, factories, tests, Postman
Database:         YES — 1 enum type, 8 tables (2 legal, 6 listing), CHECKs, 4 triggers, forced RLS + a market read policy, 5 indexes
API:              YES — 7 public + 7 customer + 7 dashboard endpoints; 4 upload purposes; suspend/reinstate side effect. Non-breaking except two potentially breaking enum growths for the Dashboard (permission strings, audit category)
Dashboard:        YES — Listings to review page (queue, chips, review panel, 4 modals), nav + 3 permission strings, audit category label
Customer App:     YES — sell flow (real uploads, reference data, branch choice, declaration), My listings, Browse, piece page; models, API services, fake backend in tests
Auth:             YES (small) — optional customer token on /market/*; a new non-elevated DB scope `market`. No change to sign-in, tokens or gates
Permissions:      YES — listing.review, listing.request_changes, listing.takedown (ceo, coo, operations)
API models/types: YES — Dashboard src/types/listing.ts, staff.ts; Flutter lib/models/piece.dart, listing.dart, reference.dart
```

**Classification**: additive → **non-breaking**, with two **potentially breaking** enum growths, both handled in the Dashboard tasks: the permission union (+3) and the audit category/event lists (+`listings`, +4 events). `customer.uploads` rises to 20/min. Suspend/reinstate keep their contract and gain a side effect.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary Dependencies**:
  - Backend: `PricingContext` / `PriceCalculator` (spec 005); `IdentityDocumentStorage`, `UploadTokenStore`, `StoreUploadRequest` (specs 001, 009); `EnsureCustomerStanding` (spec 002); `DatabaseActor` and the RLS helpers (spec 003); the `idempotent` middleware (spec 007); `RecordAuditLogAction` (spec 006); `SuspendCustomerAction` / `ReinstateCustomerAction` (spec 007); `SmsChannel`; `SystemActor`.
  - Dashboard: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`.
  - Flutter: `file_picker` (present), `ApiClient` (`postMultipart`, idempotency header).
- **Storage**: PostgreSQL 16 (enum, CHECKs, triggers, forced RLS). Media on the private encrypted disk `identity_private` under `listing-media/`. Redis for upload tokens, queue, throttles.
- **Testing**: Pest feature tests through HTTP asserting on rows, history, audit, `Notification::fake`, idempotent replay and refusals; a schema test (direct SQL: illegal move, unrecorded move, CHECKs, frozen columns); a concurrency test (two connections: approve vs request-changes, withdraw vs take down); isolation tests (seller A vs B; market scope cannot write or see drafts/private media); a leak test over every market response; the existing build tests (`CustomerTableIsolationTest`, `CustomerRouteGateTest`, `ElevationTest`, `PermissionCatalogueTest`).
- **Performance Goals**: market first page < 300 ms at 50k live listings (partial index; at most 3 queries per page besides pricing context — eager-loaded media, branches, piece type); staff queue < 300 ms; optional `perf`-group test.
- **Constraints**: no floats (bcmath strings via `Money`); named actor on every move; no seller data on the public surface; withdrawn and rejected are final, take-down and withdrawal from live only; listing media is encrypted and served in chunks, never whole in memory (R7); media responses are `no-store`.
- **Scale/Scope**: 21 endpoints, 1 Dashboard page, 4 Customer App screens.

No open NEEDS CLARIFICATION: 16 clarifications and the post-analysis decisions are in the spec; design choices are R1–R17 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every move writes a `listing_state_change` row whose CHECK requires exactly one actor; a deferred trigger refuses a move without one. Staff decisions also write `audit_log` in the same transaction | ✅ |
| II. Isolation by engine; staff authz by data | Forced RLS on `listing` and its five child tables and on `agreement_acceptance`. The market reads under a non-elevated, read-only scope limited to live/reserved rows and public media. Three catalogue codes, editable from the Dashboard, no PG grants | ✅ — with the recorded deviation below |
| III. Docs first | Updated before the migrations: `01_schema_core.sql` (`rejected`), `04_schema_market.sql` (§7 columns, CHECKs, `listing_state_change`), `05_schema_security.sql` (transition row, guard triggers, RLS block, the public-read note), `02_schema_identity.sql` (as-built note), `00_schema_full.sql`; Part 1 §4.1, §5.1, §5.3; Part 2 §0, §1 uploads, §2, §3, §10 suspend, §12; Part 3 §9.2; `api-contract.md`; `docs/features/listings.md`; CLAUDE.md "Current state" | ✅ |
| IV. Foundation before modules | Migrations mirror the updated schema; Requests, Resources, Actions, Pest (happy + refusal) and `#[OA]` on every endpoint. `buy_request` and its trigger are not scaffolded | ✅ |
| V. Test the boundary | Every listing transition and every notification is asserted through HTTP | ✅ |
| Reversible migrations | `down()` drops policies, triggers, tables and the type in reverse; listing rows and acceptances are lost on rollback (no money is involved), stated in the docblocks | ✅ |

**Recorded deviation (Principle II / Part 1 §5.3)** — the public read uses no dedicated view and no low-privilege role (product-owner decision, Clarification Q2, confirmed after analysis C1; the view is not to be reintroduced). The path is: public market → read-only `market` RLS scope → `listing` → public response Resource. Row isolation stays in the engine; column isolation is the market Resources plus `MarketLeakTest` and `MarketScopeTest`, which fail the build on any seller or private field. Part 1 §5.3 and the schema note are rewritten to say so.

**PR note** (paste into the backend PR body): *Constitution Principle II / Technical Spec Part 1 §5.3 — deviation by product-owner decision (spec 010 Clarifications): the public market has no database view and no separate low-privilege role. It reads `listing` under a read-only `market` row-level-security scope limited to live/reserved rows and public media, and returns only the market Resources; `MarketLeakTest` and `MarketScopeTest` prove no seller or private field can be returned. `docs/` is updated in this PR.*

## Project Structure

### Documentation

```text
specs/010-listings/{spec,plan,research,data-model,quickstart}.md, contracts/listings-api.md, checklists/requirements.md, tasks.md (next)
docs/features/listings.md
```

### Source Code

```text
backend
  docs/Database schema/{01_schema_core,02_schema_identity,04_schema_market,05_schema_security,00_schema_full}.sql
  docs/Technical Spec/{dahab-spec-part1-auth,dahab-spec-part2-api,dahab-spec-part3-logic}.md · docs/platform/api-contract.md · docs/features/listings.md · CLAUDE.md
  database/migrations/2026_10_03_000010_create_legal_documents.php · 2026_10_03_000020_create_listings.php
  database/seeders/LocalListingSeeder.php (+ DatabaseSeeder) · database/factories/{ListingFactory,ListingMediaFactory}.php
  config/dahab-listings.php (limits, throttles) · config/dahab-identity.php (uploads_per_minute 20) · AppServiceProvider (throttles customer.listings, public.market)
  app/Enums/{ListingState,ListingMediaKind,ListingDecision}.php · UploadPurpose (+4, maxKilobytes) · StaffPermission (+3) · AuditEvent (+4) · AuditCategory (+LISTINGS)
  app/Models/{Listing,ListingMedia,ListingBranchOption,ListingOwnershipDeclaration,ListingStateChange,LegalDocument,AgreementAcceptance}.php · Customer (+listings())
  app/Support/DatabaseActor.php (+market scope) · app/Http/Middleware/UseMarketScope.php (alias db.market, bootstrap/app.php)
  app/Support/Listings/{ListingPricer,ListingQuote,ListingCursor,ListingTransitions,MarketQuery}.php
  app/Actions/Listings/Concerns/{MovesListing,AttachesListingMedia}.php
  app/Actions/Listings/{CreateListingAction,UpdateListingAction,SubmitListingAction,WithdrawListingAction,
                        ListOwnListingsAction,ReadListingMediaAction,
                        ListMarketListingsAction,ShowMarketListingAction,
                        ListListingsForReviewAction,ShowListingForReviewAction,
                        DecideListingAction,          # approve / request changes / reject / take down
                        HoldListingsOfCustomerAction} # hold() and restore()
  app/Services/IdentityDocumentStorage.php (+storeChunkedAt, readChunked) · docker/php/uploads.ini · Dockerfile · docker/nginx/default.conf
  app/Actions/Identity/CreateCustomerUploadAction.php (+4 purposes) · app/Actions/Customers/{Suspend,Reinstate}CustomerAction.php (holds)
  app/Notifications/ListingDecisionNotification.php
  app/Exceptions/DomainApiException.php (+8) · bootstrap/app.php (DH004 → illegal_listing_transition)
  app/Http/Requests/{Customer/Listing/*, Dashboard/Listing/*, Market/*}.php · Identity/StoreUploadRequest (purposes, sizes)
  app/Http/Resources/{Market/MarketListingResource, Market/MarketListingDetailResource, Customer/ListingResource, Staff/ListingResource, ListingMediaResource}.php
  app/Http/Controllers/Api/V1/{Market/MarketListingController, Market/ReferenceController, Customer/ListingController, Dashboard/ListingController}.php · routes/api.php
  postman/Dahab-Backend.postman_collection.json (folders "Listings", "Market", "Reference"; upload purposes)
  tests/Feature/Listing/{ListingSchemaTest,CreateListingTest,UpdateListingTest,SubmitListingTest,WithdrawListingTest,OwnListingsTest,
                         ListingMediaUploadTest,ListingVideoStreamingTest,ListingMediaTest,ListingIsolationTest,ListingConcurrencyTest,
                         ReviewQueueTest,ApproveListingTest,RequestChangesTest,RejectListingTest,TakeDownListingTest,
                         ListingPermissionTest,ListingNotificationTest,ListingSuspensionHoldTest}.php
  tests/Feature/Market/{MarketListTest,MarketDetailTest,MarketPriceTest,MarketMediaTest,MarketLeakTest,MarketScopeTest,ReferenceDataTest}.php
dashboard
  src/api/endpoints.ts · src/types/{listing,staff}.ts · src/services/listing.service.ts · src/composables/useListings.ts
  src/pages/listings/index.vue · src/router/index.ts (replace the placeholder)
  src/components/listings/{ListingStateChips,ListingQueueTable,ListingReviewPanel,ListingMediaGrid,ListingMediaPreview,ListingHistory,
                           ApproveListingModal,RequestChangesModal,RejectListingModal,TakeDownListingModal,listingErrors}.{vue,ts}
  src/mock/nav.ts (listings unhidden, permissions, live badge) · audit category label · docs, CLAUDE.md
flutter (no git)
  lib/models/{piece,listing,reference}.dart · lib/services/api/{market_api,listings_api}.dart · lib/services/repositories.dart
  lib/services/mock_repositories.dart · lib/services/sell_draft.dart · lib/main.dart
  lib/features/sell/{sell1,sell2,sell3}_screen.dart · lib/features/orders/orders_screen.dart (ListingsScreen) · lib/features/catalog/{browse,detail}_screen.dart · lib/features/home/home_screen.dart
  lib/core/i18n (states, errors) · test/{test_app,flows_test}.dart (fake /market, /reference, /customer/me/listings, upload purposes)
```

**Structure Decision**: the existing layering. `app/Actions/Listings` holds every listing writer and reader; state changes go only through `MovesListing`; prices only through `ListingPricer` over the spec 005 calculator.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R17)
- [data-model.md](./data-model.md)
- [contracts/listings-api.md](./contracts/listings-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no new violations; the one deviation above is recorded and documented.

## Follow-ups (not in this feature)

- Buy requests: `reserved`, the queue trigger, `reserved → withdrawn` with releases and refunds, `queue_count` > 0.
- Category controls: `category_stopped`, and a hold cause so `suspended_hold` can mean a category pause as well as a suspension (R12).
- Market makers: `is_market_maker`, the filter and the approval.
- Object storage and HTTP range requests for video (R7); garbage collection of unclaimed uploads (analysis U5 — deliberately not in this feature).
- Structured stone data (carat, clarity, cut, lab), origin, views, saved pieces, sharing, reporting, *Change making charge* on a live listing, relisting.
- Dashboard: legal documents management, export of the queue, the customer file's Listings panel.
- In-app inbox notifications.
- A customer-facing sell-side quote so the sell flow's "You receive" preview stops using prototype maths.

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| Deferred trigger requiring a history row per move (`txid`) | "Every move is recorded with a named actor" must hold for any code path (FR-017, Principle I) | An Action-only insert has no backstop; a trigger-written row cannot know the message |
| `market` database scope + second policy | The anonymous market must read other sellers' live rows while customer tables stay closed by default | A `bootstrap`-style elevation would expose every customer table to an unauthenticated request |
| No view for the public read | Product-owner decision (Clarification Q2) | — the view was the recommended option; deviation recorded above |
| Chunked encryption format for listing media | A 50 MB video must not be held whole in memory to encrypt or serve (FR-012, analysis U1) | The existing whole-file `Crypt::encryptString` needs roughly six times the file size in memory per request |
| Three columns beyond the schema (`mime`, `position`, `state_changed_at`) | Serving a decrypted file needs its type; photos need an order; the queue needs a cheap "waiting since" | Deriving them per query from storage or history is slower and fragile |
| New state `rejected` | Product-owner decision (Clarification Q9) | — |
| Optional customer token on a public route | `is_mine` ("Yours" on Browse) | Comparing ids on the device would need the seller's id in the response |
